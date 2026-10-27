//! When a scheduled backup is due. Pure functions of a schedule, the recorded outcome of the last run and the
//! current time, generic over the time zone so tests don't depend on this machine's.
//!
//! The rule is "was the most recent scheduled moment honoured?": a computer that was asleep through 02:00 runs the
//! backup when it wakes (once, however many slots it missed), and one that is off for a week makes one backup, not
//! seven. A failed run is retried with a growing delay instead of waiting for the next slot, or hammering the server.

use chrono::{DateTime, Datelike, Duration, NaiveDate, TimeZone, Timelike};
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum Frequency {
    Off,
    /// Every hour, at `minute` past.
    Hourly,
    /// Every day at `hour:minute`.
    Daily,
    /// Once a week on `weekday` at `hour:minute`.
    Weekly,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(default)]
pub struct Schedule {
    pub frequency: Frequency,
    pub hour: u8,
    pub minute: u8,
    /// 0 = Monday … 6 = Sunday.
    pub weekday: u8,
}

impl Default for Schedule {
    fn default() -> Self {
        // Off until someone turns it on, but a sensible time (2 a.m.) ready for when they do.
        Schedule { frequency: Frequency::Off, hour: 2, minute: 0, weekday: 6 }
    }
}

impl Schedule {
    pub fn validate(&self) -> Result<(), String> {
        if self.hour > 23 || self.minute > 59 {
            return Err("Pick a time of day between 00:00 and 23:59.".into());
        }
        if self.weekday > 6 {
            return Err("Pick a day of the week.".into());
        }
        Ok(())
    }
}

/// "02:30" (or "2:30") → (hour, minute).
pub fn parse_time(text: &str) -> Result<(u8, u8), String> {
    let bad = || "Enter a time like 02:30.".to_string();
    let (h, m) = text.trim().split_once(':').ok_or_else(bad)?;
    let (h, m): (u8, u8) = (h.trim().parse().map_err(|_| bad())?, m.trim().parse().map_err(|_| bad())?);
    if h > 23 || m > 59 {
        return Err(bad());
    }
    Ok((h, m))
}

/// "mon" … "sun" (any case, or the full name) → 0 = Monday … 6 = Sunday.
pub fn parse_weekday(text: &str) -> Result<u8, String> {
    let t = text.trim().to_ascii_lowercase();
    ["mon", "tue", "wed", "thu", "fri", "sat", "sun"]
        .iter()
        .position(|d| t.len() >= 3 && t.starts_with(d))
        .map(|i| i as u8)
        .ok_or_else(|| "Enter a day like mon, tue … sun.".to_string())
}

pub fn parse_frequency(text: &str) -> Result<Frequency, String> {
    match text.trim().to_ascii_lowercase().as_str() {
        "off" | "never" => Ok(Frequency::Off),
        "hourly" | "hour" => Ok(Frequency::Hourly),
        "daily" | "day" => Ok(Frequency::Daily),
        "weekly" | "week" => Ok(Frequency::Weekly),
        _ => Err("Choose off, hourly, daily or weekly.".into()),
    }
}

/// What the last runs did, kept apart from the schedule so a person's settings are never rewritten by a backup.
#[derive(Debug, Clone, Default, PartialEq, Serialize, Deserialize)]
#[serde(default)]
pub struct RunRecord {
    /// Unix seconds.
    pub last_attempt_at: Option<i64>,
    pub last_success_at: Option<i64>,
    /// Failed attempts since the last success.
    pub failures: u32,
}

/// Wait before retrying after `failures` failed attempts in a row: 10 min, 20, 40, … capped at 2 h.
pub fn backoff(failures: u32) -> Duration {
    let minutes = 10i64.saturating_mul(1i64 << failures.saturating_sub(1).min(5));
    Duration::minutes(minutes.min(120))
}

/// A wall-clock time on a date. A time that doesn't exist (the hour skipped when clocks go forward) means the
/// moment after it instead, so the day still gets its backup; one that happens twice means the first.
fn at<Tz: TimeZone>(tz: &Tz, date: NaiveDate, hour: u32, minute: u32) -> Option<DateTime<Tz>> {
    let naive = date.and_hms_opt(hour, minute, 0)?;
    tz.from_local_datetime(&naive)
        .earliest()
        .or_else(|| tz.from_local_datetime(&(naive + Duration::hours(1))).earliest())
}

/// The latest scheduled moment at or before `now`, or `None` when the schedule is off.
pub fn latest_slot<Tz: TimeZone>(s: &Schedule, now: &DateTime<Tz>) -> Option<DateTime<Tz>> {
    let tz = now.timezone();
    match s.frequency {
        Frequency::Off => None,
        Frequency::Hourly => {
            let this_hour = at(&tz, now.date_naive(), now.hour(), s.minute as u32)?;
            Some(if this_hour <= *now { this_hour } else { this_hour - Duration::hours(1) })
        }
        Frequency::Daily => (0..3).find_map(|back| {
            let t = at(&tz, now.date_naive() - Duration::days(back), s.hour as u32, s.minute as u32)?;
            (t <= *now).then_some(t)
        }),
        Frequency::Weekly => (0..9).find_map(|back| {
            let date = now.date_naive() - Duration::days(back);
            if date.weekday().num_days_from_monday() as u8 != s.weekday {
                return None;
            }
            let t = at(&tz, date, s.hour as u32, s.minute as u32)?;
            (t <= *now).then_some(t)
        }),
    }
}

/// The first scheduled moment after `now`, or `None` when the schedule is off.
pub fn next_slot<Tz: TimeZone>(s: &Schedule, now: &DateTime<Tz>) -> Option<DateTime<Tz>> {
    let tz = now.timezone();
    match s.frequency {
        Frequency::Off => None,
        Frequency::Hourly => latest_slot(s, now).map(|t| t + Duration::hours(1)),
        Frequency::Daily | Frequency::Weekly => (0..9).find_map(|ahead| {
            let date = now.date_naive() + Duration::days(ahead);
            if s.frequency == Frequency::Weekly && date.weekday().num_days_from_monday() as u8 != s.weekday {
                return None;
            }
            let t = at(&tz, date, s.hour as u32, s.minute as u32)?;
            (t > *now).then_some(t)
        }),
    }
}

/// Is a run owed right now? `now` is Unix seconds from the same clock as the record.
pub fn is_due<Tz: TimeZone>(s: &Schedule, rec: &RunRecord, now: &DateTime<Tz>) -> bool {
    let Some(slot) = latest_slot(s, now) else { return false };
    let slot = slot.timestamp();
    if rec.last_success_at.is_some_and(|t| t >= slot) {
        return false; // that slot (or a later moment) already has a good backup
    }
    match rec.last_attempt_at {
        None => true,
        Some(a) if a < slot => true,
        Some(a) => now.timestamp() >= a + backoff(rec.failures).num_seconds(),
    }
}

/// Unix seconds of the next run, for showing ("Next backup: …"). At or before `now` means due, so soon.
pub fn next_attempt_at<Tz: TimeZone>(s: &Schedule, rec: &RunRecord, now: &DateTime<Tz>) -> Option<i64> {
    let slot = latest_slot(s, now)?.timestamp();
    if rec.last_success_at.is_some_and(|t| t >= slot) {
        return next_slot(s, now).map(|t| t.timestamp());
    }
    Some(match rec.last_attempt_at {
        Some(a) if a >= slot => a + backoff(rec.failures).num_seconds(),
        _ => now.timestamp(),
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::FixedOffset;

    fn tz() -> FixedOffset {
        FixedOffset::west_opt(5 * 3600).unwrap()
    }

    /// 2026-10-26 is a Monday.
    fn t(day: u32, h: u32, m: u32) -> DateTime<FixedOffset> {
        tz().with_ymd_and_hms(2026, 10, day, h, m, 0).unwrap()
    }

    fn sched(f: Frequency) -> Schedule {
        Schedule { frequency: f, hour: 2, minute: 30, weekday: 0 }
    }

    #[test]
    fn off_has_no_slots_and_is_never_due() {
        let s = Schedule::default();
        assert!(latest_slot(&s, &t(26, 12, 0)).is_none() && next_slot(&s, &t(26, 12, 0)).is_none());
        assert!(!is_due(&s, &RunRecord::default(), &t(26, 12, 0)));
    }

    #[test]
    fn hourly_slots_are_at_the_chosen_minute() {
        let s = sched(Frequency::Hourly);
        assert_eq!(latest_slot(&s, &t(26, 10, 45)), Some(t(26, 10, 30)));
        assert_eq!(latest_slot(&s, &t(26, 10, 10)), Some(t(26, 9, 30)));
        assert_eq!(latest_slot(&s, &t(26, 0, 10)), Some(t(25, 23, 30)));
        assert_eq!(next_slot(&s, &t(26, 10, 45)), Some(t(26, 11, 30)));
    }

    #[test]
    fn daily_slot_is_today_once_the_time_has_passed_else_yesterday() {
        let s = sched(Frequency::Daily);
        assert_eq!(latest_slot(&s, &t(26, 3, 0)), Some(t(26, 2, 30)));
        assert_eq!(latest_slot(&s, &t(26, 1, 0)), Some(t(25, 2, 30)));
        assert_eq!(next_slot(&s, &t(26, 3, 0)), Some(t(27, 2, 30)));
        assert_eq!(next_slot(&s, &t(26, 1, 0)), Some(t(26, 2, 30)));
    }

    #[test]
    fn weekly_slot_lands_on_the_chosen_weekday() {
        let s = sched(Frequency::Weekly); // Monday 02:30
        assert_eq!(latest_slot(&s, &t(28, 12, 0)), Some(t(26, 2, 30))); // Wednesday
        assert_eq!(latest_slot(&s, &t(26, 1, 0)), Some(t(19, 2, 30))); // Monday, before the time
        assert_eq!(next_slot(&s, &t(28, 12, 0)), Some(tz().with_ymd_and_hms(2026, 11, 2, 2, 30, 0).unwrap()));
        let sunday = Schedule { weekday: 6, ..s };
        assert_eq!(latest_slot(&sunday, &t(26, 12, 0)), Some(t(25, 2, 30)));
    }

    #[test]
    fn a_new_schedule_runs_at_once_and_then_only_when_a_slot_has_passed() {
        let s = sched(Frequency::Daily);
        let now = t(26, 14, 0);
        assert!(is_due(&s, &RunRecord::default(), &now), "never run: due now");
        let done = RunRecord { last_attempt_at: Some(now.timestamp()), last_success_at: Some(now.timestamp()), failures: 0 };
        assert!(!is_due(&s, &done, &t(26, 23, 0)), "today's slot is covered");
        assert!(is_due(&s, &done, &t(27, 2, 31)), "tomorrow's slot has passed");
        assert!(!is_due(&s, &done, &t(27, 2, 29)));
    }

    #[test]
    fn a_week_of_missed_slots_is_one_catch_up_run() {
        let s = sched(Frequency::Daily);
        let last = t(19, 2, 31);
        let rec = RunRecord { last_attempt_at: Some(last.timestamp()), last_success_at: Some(last.timestamp()), failures: 0 };
        assert!(is_due(&s, &rec, &t(26, 9, 0)));
        let after = RunRecord { last_attempt_at: Some(t(26, 9, 1).timestamp()), last_success_at: Some(t(26, 9, 1).timestamp()), failures: 0 };
        assert!(!is_due(&s, &after, &t(26, 9, 2)));
    }

    #[test]
    fn failures_retry_with_a_growing_delay_and_a_new_slot_resets_it() {
        let s = sched(Frequency::Daily);
        let failed = |at: DateTime<FixedOffset>, n| RunRecord { last_attempt_at: Some(at.timestamp()), last_success_at: None, failures: n };
        let rec = failed(t(26, 2, 31), 1);
        assert!(!is_due(&s, &rec, &t(26, 2, 40)), "10 minutes haven't passed");
        assert!(is_due(&s, &rec, &t(26, 2, 42)));
        let rec = failed(t(26, 2, 42), 2);
        assert!(!is_due(&s, &rec, &t(26, 3, 1)) && is_due(&s, &rec, &t(26, 3, 3)));
        // a failure last night doesn't delay tonight's slot
        assert!(is_due(&s, &failed(t(26, 2, 40), 5), &t(27, 2, 30)));
    }

    #[test]
    fn next_attempt_is_the_next_slot_when_covered_and_the_retry_time_after_a_failure() {
        let s = sched(Frequency::Daily);
        let now = t(26, 3, 0);
        let ok = RunRecord { last_attempt_at: Some(t(26, 2, 40).timestamp()), last_success_at: Some(t(26, 2, 50).timestamp()), failures: 0 };
        assert_eq!(next_attempt_at(&s, &ok, &now), Some(t(27, 2, 30).timestamp()));
        let failed = RunRecord { last_attempt_at: Some(t(26, 2, 40).timestamp()), last_success_at: None, failures: 2 };
        assert_eq!(next_attempt_at(&s, &failed, &now), Some(t(26, 3, 0).timestamp())); // 02:40 + 20 min
        assert_eq!(next_attempt_at(&s, &RunRecord::default(), &now), Some(now.timestamp()));
        assert_eq!(next_attempt_at(&Schedule::default(), &RunRecord::default(), &now), None);
    }

    #[test]
    fn backoff_doubles_and_caps() {
        let m = |n| backoff(n).num_minutes();
        assert_eq!((m(1), m(2), m(3), m(4), m(5), m(6), m(40)), (10, 20, 40, 80, 120, 120, 120));
    }

    #[test]
    fn a_time_skipped_by_daylight_saving_still_gets_a_slot() {
        // Clocks go forward at 02:00 on 2026-03-08 in America/New_York, so 02:30 doesn't exist that day.
        use chrono::offset::LocalResult;
        #[derive(Clone, Copy, Debug)]
        struct Gap;
        impl TimeZone for Gap {
            type Offset = FixedOffset;
            fn from_offset(_: &FixedOffset) -> Gap {
                Gap
            }
            fn offset_from_local_date(&self, _: &NaiveDate) -> LocalResult<FixedOffset> {
                LocalResult::Single(FixedOffset::west_opt(5 * 3600).unwrap())
            }
            fn offset_from_local_datetime(&self, l: &chrono::NaiveDateTime) -> LocalResult<FixedOffset> {
                let skipped = l.date() == NaiveDate::from_ymd_opt(2026, 3, 8).unwrap() && l.hour() == 2;
                if skipped {
                    LocalResult::None
                } else {
                    LocalResult::Single(FixedOffset::west_opt(5 * 3600).unwrap())
                }
            }
            fn offset_from_utc_date(&self, _: &NaiveDate) -> FixedOffset {
                FixedOffset::west_opt(5 * 3600).unwrap()
            }
            fn offset_from_utc_datetime(&self, _: &chrono::NaiveDateTime) -> FixedOffset {
                FixedOffset::west_opt(5 * 3600).unwrap()
            }
        }
        let s = sched(Frequency::Daily);
        let now = Gap.with_ymd_and_hms(2026, 3, 8, 12, 0, 0).unwrap();
        let slot = latest_slot(&s, &now).expect("a slot exists on the day 02:30 is skipped");
        assert_eq!((slot.date_naive(), slot.hour()), (NaiveDate::from_ymd_opt(2026, 3, 8).unwrap(), 3));
    }

    #[test]
    fn typed_schedule_parts() {
        assert_eq!(parse_time("02:30"), Ok((2, 30)));
        assert_eq!(parse_time(" 2:05 "), Ok((2, 5)));
        assert!(["", "25:00", "12:60", "noon", "12"].iter().all(|t| parse_time(t).is_err()));
        assert_eq!((parse_weekday("Mon"), parse_weekday("sunday"), parse_weekday("SAT")), (Ok(0), Ok(6), Ok(5)));
        assert!(parse_weekday("m").is_err() && parse_weekday("funday").is_err());
        assert_eq!((parse_frequency("Daily"), parse_frequency("off")), (Ok(Frequency::Daily), Ok(Frequency::Off)));
        assert!(parse_frequency("monthly").is_err());
    }

    #[test]
    fn validation() {
        assert!(Schedule { hour: 24, ..Schedule::default() }.validate().is_err());
        assert!(Schedule { minute: 60, ..Schedule::default() }.validate().is_err());
        assert!(Schedule { weekday: 7, ..Schedule::default() }.validate().is_err());
        assert!(Schedule::default().validate().is_ok());
    }
}
