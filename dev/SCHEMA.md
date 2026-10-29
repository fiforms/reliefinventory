# Database schema reference

**Generated — do not edit by hand.** Regenerate with `dev/scripts/schema-doc.sh` after adding or changing a migration, and commit the result
with it. It migrates throwaway databases (`test_reliefinventory_schemadoc*`) from scratch, so it shows what the migrations produce, not what any
live database looks like. Columns: `name type` then `?` nullable, `=default`, `PK`, `auto`. `FK` lines show `column → table.column (on delete)`.

## Warehouse schema

One per warehouse. `database/migrations/`.

### activity_log

```
id bigint unsigned PK auto
person_id bigint unsigned?
driver_id bigint unsigned?
training_team tinyint unsigned?
station tinyint unsigned?
action varchar(60)
subject_type varchar(60)?
subject_id bigint unsigned?
created_at timestamp?
```

### backups

```
id bigint unsigned PK auto
status varchar(20) =queued
scope varchar(20) =database
tiers varchar(60)?
filename varchar(255)?
size_bytes bigint unsigned?
file_count int unsigned?
progress varchar(255)?
error text?
requested_by bigint unsigned?
started_at timestamp?
finished_at timestamp?
expires_at timestamp?
created_at timestamp?
updated_at timestamp?
```

### backup_schedule

```
id tinyint unsigned PK
hourly_enabled bool =0
hourly_keep smallint unsigned =24
daily_enabled bool =1
daily_hour tinyint unsigned =2
daily_keep smallint unsigned =14
daily_with_files bool =0
weekly_enabled bool =0
weekly_dow tinyint unsigned =0
weekly_keep smallint unsigned =8
weekly_with_files bool =0
monthly_enabled bool =0
monthly_keep smallint unsigned =12
monthly_with_files bool =0
last_run_at timestamp?
last_status varchar(20)?
created_at timestamp?
updated_at timestamp?
```

### banned_emails

```
id bigint unsigned PK auto
pattern varchar(255)
created_at timestamp?
updated_at timestamp?
UNIQUE (pattern)
```

### banner_dismissals

```
id bigint unsigned PK auto
person_id bigint unsigned
version int unsigned
dismissed_at timestamp
FK person_id → people.id (cascade)
UNIQUE (person_id, version)
```

### banner_settings

```
id bigint unsigned PK auto
type enum('feedback','maintenance','message')?
message text?
version int unsigned =1
created_at timestamp?
updated_at timestamp?
```

### building_closeouts

```
id bigint unsigned PK auto
closed_at timestamp
closed_by_person_id bigint unsigned?
notes text?
created_at timestamp?
FK closed_by_person_id → people.id (set null)
```

### building_roll_calls

```
id bigint unsigned PK auto
started_at timestamp
started_by_person_id bigint unsigned?
closed_at timestamp?
closed_by_person_id bigint unsigned?
notes text?
created_at timestamp?
updated_at timestamp?
FK closed_by_person_id → people.id (set null)
FK started_by_person_id → people.id (set null)
```

### building_roll_call_confirmations

```
id bigint unsigned PK auto
building_roll_call_id bigint unsigned
volunteer_sign_in_id bigint unsigned
confirmed_by_person_id bigint unsigned?
confirmed_at timestamp
FK building_roll_call_id → building_roll_calls.id (cascade)
FK confirmed_by_person_id → people.id (set null)
FK volunteer_sign_in_id → volunteer_sign_ins.id (cascade)
UNIQUE (building_roll_call_id, volunteer_sign_in_id)
```

### building_roll_call_snapshot

```
id bigint unsigned PK auto
building_roll_call_id bigint unsigned
volunteer_sign_in_id bigint unsigned
FK building_roll_call_id → building_roll_calls.id (cascade)
FK volunteer_sign_in_id → volunteer_sign_ins.id (cascade)
UNIQUE (building_roll_call_id, volunteer_sign_in_id)
```

### cache

```
key varchar(255) PK
value mediumtext
expiration int
```

### cache_locks

```
key varchar(255) PK
owner varchar(255)
expiration int
```

### carton_codes

```
id bigint unsigned PK auto
code varchar(14)
item_id bigint unsigned
qty_per_carton int unsigned
inner_packs int unsigned?
items_per_pack int unsigned?
person_id_user bigint unsigned?
created_at timestamp?
updated_at timestamp?
FK item_id → items.id (cascade)
UNIQUE (code)
```

### categories

```
id bigint unsigned PK auto
name varchar(255)
created_at timestamp?
updated_at timestamp?
block_start int unsigned?
block_end int unsigned?
UNIQUE (name)
```

### containers

```
id bigint unsigned PK auto
container_type_id bigint unsigned
pallet_id bigint unsigned?
location_id bigint unsigned?
description text?
created_at timestamp?
updated_at timestamp?
FK container_type_id → container_types.id (restrict)
FK location_id → locations.id (set null)
FK pallet_id → pallets.id (set null)
```

### container_types

```
id bigint unsigned PK auto
name varchar(255)
created_at timestamp?
updated_at timestamp?
UNIQUE (name)
```

### counties

```
id bigint unsigned PK auto
county varchar(255)
state varchar(2)
created_at timestamp?
updated_at timestamp?
UNIQUE (county, state)
```

### design_polls

```
id bigint unsigned PK auto
slug varchar(60)
is_open bool =0
opened_at timestamp?
closed_at timestamp?
created_at timestamp?
updated_at timestamp?
UNIQUE (slug)
```

### design_poll_ideas

```
id bigint unsigned PK auto
poll_slug varchar(60)
idea text
person_id bigint unsigned?
voter_name varchar(100)?
email varchar(255)?
ok_to_contact bool =0
ip_address varchar(45)?
created_at timestamp?
updated_at timestamp?
FK person_id → people.id (set null)
```

### design_poll_reactions

```
id bigint unsigned PK auto
design_poll_vote_id bigint unsigned
option_key varchar(60)
reaction varchar(10)
comment text?
created_at timestamp?
updated_at timestamp?
FK design_poll_vote_id → design_poll_votes.id (cascade)
UNIQUE (design_poll_vote_id, option_key)
```

### design_poll_votes

```
id bigint unsigned PK auto
poll_slug varchar(60)
voter_token_hash varchar(64)
person_id bigint unsigned?
voter_name varchar(100)?
ip_address varchar(45)?
created_at timestamp?
updated_at timestamp?
FK person_id → people.id (set null)
UNIQUE (poll_slug, voter_token_hash)
```

### device_releases

```
id bigint unsigned PK auto
kind enum('app','os','print_client')
version varchar(255)
architecture varchar(255)
release_type enum('full','hotfix','disk_image','vm_image') =full
required_base_version varchar(255)?
sha256 varchar(64)
storage_path varchar(255)
filename varchar(255)
size_bytes bigint unsigned =0
download_token varchar(64)
channels longtext?
notes text?
uploaded_by bigint unsigned?
created_at timestamp?
updated_at timestamp?
FK uploaded_by → people.id (set null)
UNIQUE (download_token)
```

### device_session_tickets

```
id bigint unsigned PK auto
managed_device_id bigint unsigned
ticket_hash varchar(64)
expires_at timestamp
used_at timestamp?
created_at timestamp?
updated_at timestamp?
FK managed_device_id → managed_devices.id (cascade)
UNIQUE (ticket_hash)
```

### device_trust_grants

```
id bigint unsigned PK auto
trusted_device_id bigint unsigned
person_id bigint unsigned
granted_at timestamp
expires_at timestamp?
created_at timestamp?
updated_at timestamp?
FK person_id → people.id (cascade)
FK trusted_device_id → trusted_devices.id (cascade)
UNIQUE (trusted_device_id, person_id)
```

### donation_offers

```
id bigint unsigned PK auto
person_id bigint unsigned
contact_person_id bigint unsigned?
status varchar(255) =offered
eta_start date?
eta_end date?
transit_notes text?
refused_reason text?
diverted_to text?
cancelled_reason text?
description text?
donation_id bigint unsigned?
entered_by_person_id bigint unsigned?
created_at timestamp?
updated_at timestamp?
FK contact_person_id → people.id (set null)
FK donation_id → orderdonations.id (set null)
FK entered_by_person_id → people.id (set null)
FK person_id → people.id (restrict)
```

### donation_offer_status_log

```
id bigint unsigned PK auto
donation_offer_id bigint unsigned
from_status varchar(255)?
to_status varchar(255)
changed_by_person_id bigint unsigned?
contact_method varchar(255)?
notes text?
created_at timestamp?
FK changed_by_person_id → people.id (set null)
FK donation_offer_id → donation_offers.id (cascade)
```

### donor_standing_log

```
id bigint unsigned PK auto
person_id bigint unsigned
from_standing varchar(20)
to_standing varchar(20)
note text?
person_id_user bigint unsigned?
created_at timestamp?
updated_at timestamp?
FK person_id → people.id (cascade)
```

### drivers

```
id bigint unsigned PK auto
name varchar(255)
phone varchar(255)?
carrier varchar(255)?
pin_hash varchar(255)?
person_id bigint unsigned?
created_at timestamp?
updated_at timestamp?
training_team tinyint unsigned?
FK person_id → people.id (set null)
```

### failed_jobs

```
id bigint unsigned PK auto
uuid varchar(255)
connection text
queue text
payload longtext
exception longtext
failed_at timestamp =current_timestamp()
UNIQUE (uuid)
```

### federation_identities

```
id bigint unsigned PK auto
tenant_id bigint unsigned?
uuid uuid
name varchar(255)
base_url varchar(255)?
public_key varchar(64)
private_key text?
created_at timestamp?
updated_at timestamp?
UNIQUE (tenant_id)
UNIQUE (uuid)
```

### feedback_aggregation_settings

```
id bigint unsigned PK auto
instance_role enum('remote','central') =remote
created_at timestamp?
updated_at timestamp?
feedback_trust_id bigint unsigned?
FK feedback_trust_id → trusts.id (set null)
```

### feedback_reports

```
id bigint unsigned PK auto
uuid uuid?
instance_identifier varchar(255)?
warehouse_id varchar(8)?
person_id bigint unsigned?
reporter_name varchar(255)?
reporter_email varchar(255)?
type enum('bug','feature')
urgent bool =0
flagged_for_review bool =0
flagged_reason varchar(255)?
status enum('new','seen','in_development','review','resolved')? =new
status_changed_at timestamp?
submitted_at timestamp?
message text
page_url varchar(2048)
page_title varchar(255)?
user_agent varchar(255)?
screenshot_path varchar(255)?
commit_hash varchar(16)?
app_version varchar(64)?
created_at timestamp?
updated_at timestamp?
trust_id bigint unsigned?
FK person_id → people.id (restrict)
FK trust_id → trusts.id (set null)
UNIQUE (instance_identifier, uuid)
```

### feedback_report_status_logs

```
id bigint unsigned PK auto
feedback_report_id bigint unsigned
status enum('new','seen','in_development','review','resolved')?
comment text?
person_id bigint unsigned
created_at timestamp?
FK feedback_report_id → feedback_reports.id (cascade)
FK person_id → people.id (restrict)
```

### firmware_settings

```
id bigint unsigned PK auto
source_url varchar(2048)?
created_at timestamp?
updated_at timestamp?
```

### forms

```
id bigint unsigned PK auto
name varchar(255)
slug varchar(255)
intro_text text?
status varchar(255) =draft
access_mode varchar(255) =staff_only
requires_approval bool =0
on_approval_action varchar(255) =none
notify_person_ids longtext?
notify_emails text?
created_by_person_id bigint unsigned?
created_at timestamp?
updated_at timestamp?
FK created_by_person_id → people.id (set null)
UNIQUE (slug)
```

### form_answers

```
id bigint unsigned PK auto
form_submission_id bigint unsigned
form_question_id bigint unsigned?
question_label_snapshot varchar(255)
question_type_snapshot varchar(255)
value_text text?
value_json longtext?
created_at timestamp?
FK form_question_id → form_questions.id (set null)
FK form_submission_id → form_submissions.id (cascade)
```

### form_questions

```
id bigint unsigned PK auto
form_id bigint unsigned
order int unsigned =0
label varchar(255)
help_text text?
type varchar(255)
options longtext?
required bool =0
preset_key varchar(255)?
target_field varchar(255)?
created_at timestamp?
updated_at timestamp?
FK form_id → forms.id (cascade)
```

### form_question_presets

```
id bigint unsigned PK auto
key varchar(255)
label varchar(255)
category varchar(255) =other
type varchar(255)
options longtext?
help_text text?
target_field varchar(255)?
order int unsigned =0
created_at timestamp?
updated_at timestamp?
UNIQUE (key)
```

### form_submissions

```
id bigint unsigned PK auto
form_id bigint unsigned
approval_status varchar(255)?
reviewed_by_person_id bigint unsigned?
reviewed_at timestamp?
submitted_by_person_id bigint unsigned?
submitter_name varchar(255)?
submitter_email varchar(255)?
submitter_phone varchar(255)?
ip_address varchar(255)?
linked_person_id bigint unsigned?
created_at timestamp?
updated_at timestamp?
FK form_id → forms.id (cascade)
FK linked_person_id → people.id (set null)
FK reviewed_by_person_id → people.id (set null)
FK submitted_by_person_id → people.id (set null)
```

### form_submission_status_log

```
id bigint unsigned PK auto
form_submission_id bigint unsigned
from_status varchar(255)?
to_status varchar(255)?
changed_by_person_id bigint unsigned?
notes text?
created_at timestamp?
FK changed_by_person_id → people.id (set null)
FK form_submission_id → form_submissions.id (cascade)
```

### import_batches

```
id bigint unsigned PK auto
source varchar(255)
file_type varchar(255)
original_filename varchar(255)
stored_path varchar(255)
status enum('previewed','committing','completed','failed') =previewed
summary longtext?
created_by bigint unsigned?
started_at timestamp?
completed_at timestamp?
created_at timestamp?
updated_at timestamp?
FK created_by → people.id (set null)
```

### import_batch_rows

```
id bigint unsigned PK auto
import_batch_id bigint unsigned
row_number int unsigned
source_key varchar(255)?
outcome enum('created','updated','skipped','error')?
error_message text?
raw_row longtext?
mapped_entity_type varchar(255)?
mapped_entity_id bigint unsigned?
created_at timestamp?
updated_at timestamp?
FK import_batch_id → import_batches.id (cascade)
```

### items

```
id bigint unsigned PK auto
itemtype_id bigint unsigned?
packagetypes_id bigint unsigned
pluscode varchar(4)?
size decimal(8,2)?
case_qty int?
active bool =1
description text?
upc varchar(50)?
created_at timestamp?
updated_at timestamp?
FK itemtype_id → itemtypes.id (cascade)
FK packagetypes_id → packagetypes.id (restrict)
UNIQUE (upc)
```

### itemtypes

```
id bigint unsigned PK auto
category_id bigint unsigned
unit_id bigint unsigned
name varchar(255)
low_stock_threshold int unsigned?
description text?
active bool =1
created_at timestamp?
updated_at timestamp?
family varchar(4)?
variant varchar(2)?
status enum('orderable','sort_hold','retired') =orderable
pick_sequence tinyint unsigned?
storage_class enum('F','L','K','A','N')?
source_system varchar(255)?
source_ref varchar(255)?
FK category_id → categories.id (restrict)
FK unit_id → units.id (restrict)
UNIQUE (family, variant)
```

### item_ledgers

```
id bigint unsigned PK auto
orderdonation_id bigint unsigned
item_id bigint unsigned
pallet_id bigint unsigned?
stock_pallet_id bigint unsigned?
order_line_id bigint unsigned?
qty_added int =0
qty_subtracted int =0
disposition enum('usable','outdated','trashed','diverted') =usable
item_condition varchar(10)?
expires_on date?
person_id_user bigint unsigned?
created_at timestamp?
updated_at timestamp?
FK item_id → items.id (restrict)
FK orderdonation_id → orderdonations.id (cascade)
FK order_line_id → orderlines.id (set null)
FK pallet_id → pallets.id (set null)
FK person_id_user → people.id (set null)
FK stock_pallet_id → pallets.id (set null)
```

### jobs

```
id bigint unsigned PK auto
queue varchar(255)
payload longtext
attempts tinyint unsigned
reserved_at int unsigned?
available_at int unsigned
created_at int unsigned
```

### job_batches

```
id varchar(255) PK
name varchar(255)
total_jobs int
pending_jobs int
failed_jobs int
failed_job_ids longtext
options mediumtext?
cancelled_at int?
created_at int
finished_at int?
```

### kiosk_locations

```
id bigint unsigned PK auto
name varchar(255)
welcome_message varchar(255)?
active bool =1
created_at timestamp?
updated_at timestamp?
```

### kiosk_settings

```
id bigint unsigned PK auto
idle_reset_minutes int unsigned?
created_at timestamp?
updated_at timestamp?
```

### kiosk_suggestions

```
id bigint unsigned PK auto
kind varchar(255)
value varchar(255)
created_at timestamp?
updated_at timestamp?
UNIQUE (kind, value)
```

### label_settings

```
id bigint unsigned PK auto
pallet_label_media varchar(40) =label_large
inventory_label_media varchar(40) =label_small
created_at timestamp?
updated_at timestamp?
```

### locations

```
id bigint unsigned PK auto
PullSequence int?
Route varchar(255)?
Block varchar(255)?
Aisle varchar(255)?
Lane varchar(255)?
Stack varchar(255)?
use_id bigint unsigned?
code text
status enum('active','archived')
created_at timestamp?
updated_at timestamp?
warehouse_id bigint unsigned
FK use_id → uses.id (set null)
FK warehouse_id → warehouses.id (cascade)
UNIQUE (code)
```

### login_history

```
id bigint unsigned PK auto
person_id bigint unsigned
method varchar(255)
ip_address varchar(255)?
user_agent text?
logged_in_at timestamp
created_at timestamp?
updated_at timestamp?
FK person_id → people.id (cascade)
```

### mail_settings

```
id bigint unsigned PK auto
mailer varchar(255)?
from_address varchar(255)?
from_name varchar(255)?
resend_key text?
smtp_host varchar(255)?
smtp_port int unsigned?
smtp_username varchar(255)?
smtp_password text?
feedback_notify_emails text?
created_at timestamp?
updated_at timestamp?
```

### managed_devices

```
id bigint unsigned PK auto
trust_id bigint unsigned?
device_uuid varchar(255)
name varchar(255)
mac_address varchar(255)?
kiosk_location_id bigint unsigned?
session_token_hash varchar(64)?
revoked_at timestamp?
update_channel varchar(20) =stable
os_auto_update_enabled bool =1
kiosk_function varchar(40)?
language varchar(10)?
language_revision int unsigned =0
app_version varchar(255)?
os_version varchar(255)?
architecture varchar(255)?
hostname varchar(255)?
cpu_temp_c decimal(5,1)?
last_ip varchar(45)?
last_seen_at timestamp?
paired_at timestamp?
created_at timestamp?
updated_at timestamp?
platform varchar(20)?
reported_printers longtext?
printers_reported_at timestamp?
wake_channel varchar(64)?
FK kiosk_location_id → kiosk_locations.id (set null)
FK trust_id → trusts.id (set null)
UNIQUE (device_uuid)
UNIQUE (session_token_hash)
UNIQUE (wake_channel)
```

### menu_items

```
id bigint unsigned PK auto
page_id bigint unsigned?
link_text varchar(255)
link_url varchar(255)?
submenu_page_id bigint unsigned?
graphic_url varchar(255)?
order int =0
group_label varchar(255)?
permission_key varchar(255)?
created_at timestamp?
updated_at timestamp?
FK page_id → pages.id (cascade)
FK submenu_page_id → pages.id (cascade)
```

### notifications

```
id uuid PK
type varchar(255)
notifiable_type varchar(255)
notifiable_id bigint unsigned
data text
read_at timestamp?
created_at timestamp?
updated_at timestamp?
```

### offline_mode_settings

```
id bigint unsigned PK auto
enabled bool =0
created_at timestamp?
updated_at timestamp?
```

### orderdonations

```
id bigint unsigned PK auto
type enum('order','donation','adjustment')
category enum('donation','equipment','supplies','other')?
category_other varchar(255)?
container_count int unsigned?
manifest text?
manifest_weight_lbs decimal(10,2)?
driver_id bigint unsigned?
arrival_method enum('semi','box_truck','personal_vehicle','delivery_truck','trailer','other')?
arrival_method_other varchar(255)?
carrier varchar(255)?
container_types longtext?
container_type_counts longtext?
quick_sort_candidate bool?
source_address text?
source_city varchar(255)?
source_state varchar(2)?
source_zip varchar(10)?
person_id bigint unsigned?
contact_person_id bigint unsigned?
donor_identification_pending bool =0
status_id bigint unsigned
status_changed_at timestamp?
person_id_user bigint unsigned?
order_date date
comments text?
photo_path varchar(255)?
fulfillment_method enum('delivery','pickup')?
needed_by_date date?
delivery_days longtext?
preferred_time varchar(100)?
contact_name varchar(191)?
contact_phone varchar(50)?
other_needs text?
special_instructions text?
pallet_count smallint unsigned?
bol_number varchar(255)?
signed_bol_path varchar(255)?
bol_rejection_reason text?
bol_reviewed_by_person_id bigint unsigned?
created_at timestamp?
updated_at timestamp?
source_system varchar(255)?
source_ref varchar(255)?
adjustment_reason varchar(30)?
FK bol_reviewed_by_person_id → people.id (set null)
FK contact_person_id → people.id (set null)
FK driver_id → drivers.id (set null)
FK person_id → people.id (cascade)
FK status_id → statuses.id (restrict)
```

### orderlines

```
id bigint unsigned PK auto
orderdonation_id bigint unsigned
itemtype_id bigint unsigned
packagetype_id bigint unsigned?
qty_requested int
qty_approved int unsigned?
approved_by_person_id bigint unsigned?
approved_at timestamp?
comments text?
need_level enum('critical','moderate','low')?
created_at timestamp?
updated_at timestamp?
FK approved_by_person_id → people.id (set null)
FK itemtype_id → itemtypes.id (restrict)
FK orderdonation_id → orderdonations.id (cascade)
FK packagetype_id → packagetypes.id (restrict)
```

### packagetypes

```
id bigint unsigned PK auto
singular varchar(255)
plural varchar(255)?
UNIQUE (singular, plural)
```

### pages

```
id bigint unsigned PK auto
hashtag varchar(255)
menu_title varchar(255)
header_text varchar(255)
content text?
created_at timestamp?
updated_at timestamp?
UNIQUE (hashtag)
```

### pallets

```
id bigint unsigned PK auto
kind enum('R','W','S','H','Q')
status varchar(255)
status_before_missing varchar(255)?
container_type enum('pallet','gaylord','box','bag','tote') =pallet
donor_person_id bigint unsigned?
destination_person_id bigint unsigned?
truck_id bigint unsigned?
earliest_expiry date?
condition enum('pending','good','condemned')?
datepacked date
created_at timestamp?
updated_at timestamp?
location_id bigint unsigned?
orderdonation_id bigint unsigned?
content_description varchar(255)?
content_item_id bigint unsigned?
FK content_item_id → items.id (set null)
FK destination_person_id → people.id (set null)
FK donor_person_id → people.id (set null)
FK location_id → locations.id (set null)
FK orderdonation_id → orderdonations.id (set null)
FK truck_id → trucks.id (set null)
```

### palletstatus

```
pallet_id bigint unsigned
location_id bigint unsigned?
status varchar(255)
notes text?
id bigint unsigned PK auto
created_at timestamp?
updated_at timestamp?
FK location_id → locations.id (restrict)
FK pallet_id → pallets.id (cascade)
```

### pallet_stock_assignments

```
id bigint unsigned PK auto
pallet_id bigint unsigned
item_id bigint unsigned
qty int
expires_on date?
person_id_user bigint unsigned?
notes text?
created_at timestamp?
updated_at timestamp?
FK item_id → items.id (cascade)
FK pallet_id → pallets.id (cascade)
```

### people

```
id bigint unsigned PK auto
account_id bigint unsigned?
system_key varchar(50)?
first_name varchar(255)?
last_name varchar(255)?
organization varchar(255)?
is_organization bool =0
parent_person_id bigint unsigned?
contact_role varchar(100)?
category_id bigint unsigned?
phone varchar(255)?
email varchar(255)?
requested_track enum('volunteer','donor','partner')?
approved_at timestamp?
pin_hash varchar(255)?
badge_code varchar(255)?
remember_token varchar(100)?
address text?
city varchar(255)?
state varchar(2)?
zip varchar(10)?
address_verified_at timestamp?
comments text?
created_at timestamp?
updated_at timestamp?
county_id bigint unsigned?
disabled_at timestamp?
is_volunteer bool =0
partner_status varchar(255)?
volunteer_active bool =1
volunteer_window_start date?
volunteer_window_end date?
source_system varchar(255)?
source_ref varchar(255)?
donor_standing varchar(20) =normal
donor_standing_note text?
donor_standing_set_by bigint unsigned?
donor_standing_set_at timestamp?
password_change_required bool =0
created_by_person_id bigint unsigned?
approved_by_person_id bigint unsigned?
is_trainee bool =0
training_team tinyint unsigned?
FK approved_by_person_id → people.id (set null)
FK category_id → person_categories.id (set null)
FK county_id → counties.id (set null)
FK created_by_person_id → people.id (set null)
FK parent_person_id → people.id (set null)
UNIQUE (account_id)
UNIQUE (badge_code)
UNIQUE (email)
UNIQUE (system_key)
```

### people_roles

```
id bigint unsigned PK auto
person_id bigint unsigned
role_id bigint unsigned
created_at timestamp?
updated_at timestamp?
FK person_id → people.id (cascade)
FK role_id → roles.id (cascade)
UNIQUE (person_id, role_id)
```

### permissions

```
id bigint unsigned PK auto
key varchar(255)
name varchar(255)
description text?
created_at timestamp?
updated_at timestamp?
UNIQUE (key)
```

### person_categories

```
id bigint unsigned PK auto
name varchar(255)
created_at timestamp?
updated_at timestamp?
UNIQUE (name)
```

### person_partner_status_log

```
id bigint unsigned PK auto
person_id bigint unsigned
from_status varchar(255)?
to_status varchar(255)?
changed_by_person_id bigint unsigned?
form_submission_id bigint unsigned?
notes text?
created_at timestamp?
FK changed_by_person_id → people.id (set null)
FK form_submission_id → form_submissions.id (set null)
FK person_id → people.id (cascade)
```

### person_permissions

```
id bigint unsigned PK auto
person_id bigint unsigned
permission_id bigint unsigned
granted bool
created_at timestamp?
updated_at timestamp?
FK permission_id → permissions.id (cascade)
FK person_id → people.id (cascade)
UNIQUE (person_id, permission_id)
```

### pin_login_settings

```
id bigint unsigned PK auto
enabled bool =0
trust_mode enum('time_of_day','session_duration','indefinite') =session_duration
trust_time_of_day time?
trust_session_minutes int unsigned? =480
require_badge_and_pin bool =0
badge_login_enabled bool =0
created_at timestamp?
updated_at timestamp?
```

### printers

```
id bigint unsigned PK auto
name varchar(255)
managed_device_id bigint unsigned
queue_name varchar(255)
kiosk_location_id bigint unsigned?
media varchar(40)?
active bool =0
sort_order int unsigned =0
created_at timestamp?
updated_at timestamp?
FK kiosk_location_id → kiosk_locations.id (set null)
FK managed_device_id → managed_devices.id (cascade)
UNIQUE (managed_device_id, queue_name)
```

### print_jobs

```
id bigint unsigned PK auto
printer_id bigint unsigned?
kiosk_location_id bigint unsigned?
media varchar(40)
title varchar(255)
document varchar(60)?
pdf_path varchar(255)?
copies smallint unsigned =1
status varchar(20) =queued
claimed_by_device_id bigint unsigned?
claimed_at timestamp?
claim_expires_at timestamp?
attempts smallint unsigned =0
error_message text?
requested_by bigint unsigned?
printed_at timestamp?
created_at timestamp?
updated_at timestamp?
FK claimed_by_device_id → managed_devices.id (set null)
FK kiosk_location_id → kiosk_locations.id (set null)
FK printer_id → printers.id (set null)
FK requested_by → people.id (set null)
```

### print_job_status_log

```
id bigint unsigned PK auto
print_job_id bigint unsigned
from_status varchar(20)?
to_status varchar(20)
changed_by_person_id bigint unsigned?
managed_device_id bigint unsigned?
notes text?
created_at timestamp =current_timestamp()
FK changed_by_person_id → people.id (set null)
FK managed_device_id → managed_devices.id (set null)
FK print_job_id → print_jobs.id (cascade)
```

### roles

```
id bigint unsigned PK auto
name varchar(255)
description text?
created_at timestamp?
updated_at timestamp?
visible_in_people_form bool =0
visible_in_user_admin bool =0
UNIQUE (name)
```

### role_permissions

```
id bigint unsigned PK auto
role_id bigint unsigned
permission_id bigint unsigned
created_at timestamp?
updated_at timestamp?
FK permission_id → permissions.id (cascade)
FK role_id → roles.id (cascade)
UNIQUE (role_id, permission_id)
```

### sessions

```
id varchar(255) PK
user_id bigint unsigned?
ip_address varchar(45)?
user_agent text?
last_url varchar(255)?
payload longtext
last_activity int
```

### setup_state

```
id bigint unsigned PK auto
completed_at timestamp?
demo_data_seeded_at timestamp?
created_at timestamp?
updated_at timestamp?
```

### sign_in_categories

```
id bigint unsigned PK auto
kiosk_location_id bigint unsigned
name varchar(255)
created_at timestamp?
updated_at timestamp?
FK kiosk_location_id → kiosk_locations.id (cascade)
UNIQUE (kiosk_location_id, name)
```

### statuses

```
id bigint unsigned PK auto
name varchar(255)
description text?
created_at timestamp?
updated_at timestamp?
UNIQUE (name)
```

### streams

```
id bigint unsigned PK auto
name varchar(255)
warehouse_id bigint unsigned
counts_kind enum('R','W','S','H','Q')?
counts_status varchar(255)?
counts_condition enum('pending','good','condemned')?
threshold int unsigned?
created_at timestamp?
updated_at timestamp?
FK warehouse_id → warehouses.id (cascade)
```

### training_products

```
id bigint unsigned PK auto
item_id bigint unsigned
qty int unsigned =0
person_id_user bigint unsigned?
created_at timestamp?
updated_at timestamp?
FK item_id → items.id (cascade)
FK person_id_user → people.id (set null)
UNIQUE (item_id)
```

### training_registrations

```
id bigint unsigned PK auto
person_id bigint unsigned
email varchar(255)?
phone varchar(50)?
wants_updates bool =0
help_volunteer bool =0
help_software bool =0
help_organization bool =0
help_other bool =0
recommend text?
comments text?
ip_address varchar(45)?
user_agent varchar(255)?
created_at timestamp?
updated_at timestamp?
FK person_id → people.id (cascade)
```

### training_settings

```
id bigint unsigned PK auto
prepared_at timestamp?
prepared_by bigint unsigned?
prepared_summary longtext?
event_title varchar(150)?
event_location varchar(150)?
trainer1_name varchar(100)?
trainer1_signature varchar(255)?
trainer2_name varchar(100)?
trainer2_signature varchar(255)?
created_at timestamp?
updated_at timestamp?
```

### trucks

```
id bigint unsigned PK auto
donor_person_id bigint unsigned?
status enum('received','unloaded') =received
trailer_number varchar(255)?
rough_pallet_estimate int unsigned?
contents_summary text?
manifest_weight_lbs decimal(10,2)?
created_at timestamp?
updated_at timestamp?
FK donor_person_id → people.id (set null)
```

### trusted_devices

```
id bigint unsigned PK auto
device_token varchar(64)
label varchar(255)?
status enum('pending','approved','revoked','denied') =pending
requested_by_person_id bigint unsigned?
request_reason text?
kiosk_mode bool =0
kiosk_mode_enabled_at timestamp?
kiosk_mode_enabled_by_person_id bigint unsigned?
kiosk_location_id bigint unsigned?
user_agent varchar(255)?
ip_address varchar(45)?
last_ip_address varchar(45)?
browser varchar(100)?
os varchar(100)?
requested_at timestamp
approved_by bigint unsigned?
approved_at timestamp?
last_seen_at timestamp?
created_at timestamp?
updated_at timestamp?
FK approved_by → people.id (set null)
FK kiosk_location_id → kiosk_locations.id (set null)
FK kiosk_mode_enabled_by_person_id → people.id (set null)
FK requested_by_person_id → people.id (set null)
UNIQUE (device_token)
```

### trusts

```
id bigint unsigned PK auto
identity_id bigint unsigned
kind varchar(32)
peer_uuid varchar(64)
name varchar(255)
public_key varchar(64)
base_url varchar(255)?
status varchar(16) =approved
session_epoch int unsigned =0
auth_method varchar(32)
approved_by_person_id bigint unsigned?
approved_by_label varchar(255)?
approved_at timestamp?
domain_verified_at timestamp?
revoked_at timestamp?
last_seen_at timestamp?
last_seen_ip varchar(64)?
created_at timestamp?
updated_at timestamp?
FK approved_by_person_id → people.id (set null)
FK identity_id → federation_identities.id (cascade)
UNIQUE (identity_id, peer_uuid)
```

### trust_audit_log

```
id bigint unsigned PK auto
trust_id bigint unsigned?
event varchar(48)
actor_person_id bigint unsigned?
detail longtext?
created_at timestamp =current_timestamp()
FK actor_person_id → people.id (set null)
FK trust_id → trusts.id (set null)
```

### trust_grants

```
id bigint unsigned PK auto
trust_id bigint unsigned
permission varchar(48)
direction varchar(8)
created_at timestamp?
updated_at timestamp?
FK trust_id → trusts.id (cascade)
UNIQUE (trust_id, permission, direction)
```

### trust_pairing_codes

```
id bigint unsigned PK auto
code_hash char(64)
kind varchar(32)
name_hint varchar(255)?
grants longtext
kiosk_location_id bigint unsigned?
created_by bigint unsigned?
expires_at timestamp
used_at timestamp?
trust_id bigint unsigned?
created_at timestamp?
updated_at timestamp?
FK created_by → people.id (set null)
FK kiosk_location_id → kiosk_locations.id (set null)
FK trust_id → trusts.id (set null)
UNIQUE (code_hash)
```

### units

```
id bigint unsigned PK auto
abbreviation varchar(255)
name varchar(255)
type enum('weight','volume','each','other')
UNIQUE (abbreviation)
UNIQUE (name)
```

### uses

```
id bigint unsigned PK auto
use varchar(255)
created_at timestamp?
updated_at timestamp?
UNIQUE (use)
```

### volunteer_sign_ins

```
id bigint unsigned PK auto
person_id bigint unsigned
category varchar(255)
other_category_id bigint unsigned?
other_category_text varchar(255)?
agency varchar(255)?
title_function varchar(255)?
work_site varchar(255)?
description_of_work text?
expected_departure_at timestamp?
signed_in_at timestamp
signed_out_at timestamp?
status varchar(255) =open
certified_at timestamp?
certified_by_person_id bigint unsigned?
created_at timestamp?
updated_at timestamp?
FK certified_by_person_id → people.id (set null)
FK other_category_id → sign_in_categories.id (set null)
FK person_id → people.id (cascade)
```

### volunteer_sign_in_audit_log

```
id bigint unsigned PK auto
volunteer_sign_in_id bigint unsigned
field varchar(255)
old_value text?
new_value text?
changed_by_person_id bigint unsigned?
created_at timestamp?
FK changed_by_person_id → people.id (set null)
FK volunteer_sign_in_id → volunteer_sign_ins.id (cascade)
```

### warehouses

```
id bigint unsigned PK auto
name varchar(255)
address varchar(255)
city varchar(255)
state varchar(2)
zip varchar(10)
county_id bigint unsigned?
manager_id bigint unsigned?
created_at timestamp?
updated_at timestamp?
pallets_enabled bool =1
FK county_id → counties.id (set null)
FK manager_id → people.id (set null)
UNIQUE (name)
```

## Control schema

Instance-wide: which warehouses exist. `database/migrations/control/`.

### failed_jobs

```
id bigint unsigned PK auto
uuid varchar(255)
connection text
queue text
payload longtext
exception longtext
failed_at timestamp =current_timestamp()
UNIQUE (uuid)
```

### instance_audit_log

```
id bigint unsigned PK auto
actor varchar(255)
action varchar(60)
warehouse_id char(8)?
subject varchar(255)?
details longtext?
created_at timestamp =current_timestamp()
```

### instance_backup_schedule

```
id tinyint unsigned PK
enabled bool =0
daily_hour tinyint unsigned =3
daily_keep smallint unsigned =7
daily_with_files bool =0
weekly_enabled bool =0
weekly_dow tinyint unsigned =0
weekly_keep smallint unsigned =4
weekly_with_files bool =0
last_daily_at timestamp?
last_weekly_at timestamp?
last_run_at timestamp?
last_status varchar(20)?
last_message varchar(1000)?
created_at timestamp?
updated_at timestamp?
```

### instance_pairing_codes

```
id bigint unsigned PK auto
code_hash char(64)
permissions longtext
name_hint varchar(255)?
created_by varchar(255)?
expires_at timestamp
used_at timestamp?
trust_id bigint unsigned?
created_at timestamp?
updated_at timestamp?
UNIQUE (code_hash)
```

### instance_recovery_key

```
id tinyint unsigned PK
public_key varchar(43)
fingerprint varchar(19)
source varchar(20)
created_by varchar(255)?
acknowledged_at timestamp?
acknowledged_by varchar(255)?
created_at timestamp?
updated_at timestamp?
```

### instance_trusts

```
id bigint unsigned PK auto
peer_uuid varchar(64)
name varchar(255)
public_key varchar(43)
status varchar(20) =approved
permissions longtext
session_epoch int unsigned =1
approved_by varchar(255)?
approved_at timestamp?
revoked_at timestamp?
last_seen_at timestamp?
last_seen_ip varchar(64)?
created_at timestamp?
updated_at timestamp?
UNIQUE (peer_uuid)
```

### instance_update

```
id bigint unsigned PK auto
action varchar(10)?
state varchar(20) =idle
requested_by varchar(255)?
requested_at timestamp?
message varchar(1000)?
current_version varchar(60)?
available_version varchar(60)?
last_checked_at timestamp?
state_changed_at timestamp?
```

### jobs

```
id bigint unsigned PK auto
queue varchar(255)
payload longtext
attempts tinyint unsigned
reserved_at int unsigned?
available_at int unsigned
created_at int unsigned
```

### job_batches

```
id varchar(255) PK
name varchar(255)
total_jobs int
pending_jobs int
failed_jobs int
failed_job_ids longtext
options mediumtext?
cancelled_at int?
created_at int
finished_at int?
```

### provisioning_commands

```
id bigint unsigned PK auto
warehouse_id char(8)?
command varchar(20)
status varchar(10) =pending
requested_by varchar(255)?
message text?
started_at timestamp?
finished_at timestamp?
created_at timestamp?
updated_at timestamp?
FK warehouse_id → warehouses.id (cascade)
```

### warehouses

```
id char(8) PK
uuid uuid
schema_name varchar(64)
display_name varchar(255)
admin_email varchar(255)?
status varchar(20) =active
is_default bool =0
listed bool =1
is_training bool =0
migration_state varchar(20)?
notes text?
created_at timestamp?
updated_at timestamp?
archived_at timestamp?
status_before_archive varchar(20)?
UNIQUE (display_name)
UNIQUE (schema_name)
UNIQUE (uuid)
```

### warehouse_domains

```
id bigint unsigned PK auto
hostname varchar(255)
warehouse_id char(8)
is_primary bool =0
skip_chooser bool =1
turnstile_confirmed_at timestamp?
created_at timestamp?
updated_at timestamp?
FK warehouse_id → warehouses.id (cascade)
UNIQUE (hostname)
```

### warehouse_setup_codes

```
id bigint unsigned PK auto
warehouse_id char(8)
code_hash char(64)
expires_at timestamp
used_at timestamp?
revoked_at timestamp?
created_by varchar(255)?
created_at timestamp?
updated_at timestamp?
FK warehouse_id → warehouses.id (cascade)
```

## Accounts schema

Instance-wide: logins and memberships. `database/migrations/accounts/`.

### accounts

```
id bigint unsigned PK auto
email varchar(255)
password varchar(255)?
email_verified_at timestamp?
locked_at timestamp?
is_instance_admin bool =0
auth_epoch int unsigned =0
created_at timestamp?
updated_at timestamp?
UNIQUE (email)
```

### cache

```
key varchar(255) PK
value mediumtext
expiration int
```

### cache_locks

```
key varchar(255) PK
owner varchar(255)
expiration int
```

### memberships

```
id bigint unsigned PK auto
account_id bigint unsigned
warehouse_id char(8)
person_id bigint unsigned
status varchar(20) =active
is_training bool =0
created_at timestamp?
updated_at timestamp?
FK account_id → accounts.id (cascade)
UNIQUE (account_id, warehouse_id)
UNIQUE (warehouse_id, person_id)
```

### membership_invitations

```
id bigint unsigned PK auto
warehouse_id char(8)
person_id bigint unsigned
email varchar(255)
token_hash char(64)?
status varchar(20) =pending
source varchar(20)
invited_by varchar(255)?
expires_at timestamp
responded_at timestamp?
created_at timestamp?
updated_at timestamp?
UNIQUE (warehouse_id, person_id)
```

### password_reset_tokens

```
email varchar(255) PK
token varchar(255)
created_at timestamp?
```
