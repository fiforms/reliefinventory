"""Tests for ri-updater.py with docker and the app faked: cd docker/updater && python3 -I -m unittest discover -s . -p "test_ri_updater.py""""
import importlib.util
import json
import os
import unittest

spec = importlib.util.spec_from_file_location("ri_updater", os.path.join(os.path.dirname(__file__), "ri-updater.py"))
ri = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ri)


class Fake:
    """A fake docker + app. `images` maps image ref -> (id, version); `pulled` is what `pull` makes `latest` become."""

    def __init__(self, running=("sha256:old", "1.3.0"), pulled=("sha256:new", "1.4.0"), fail=None):
        self.calls, self.reports = [], []
        self.running, self.pulled, self.fail = running, pulled, fail
        self.images = {"img:latest": running, running[0]: running}
        self.app_up = True

    def runner(self, cmd, timeout=0):
        self.calls.append(cmd)
        args = cmd[1:]
        if args[:1] == ["compose"]:
            sub = args[3:]
            if sub[:1] == ["config"]:
                return 0, json.dumps({"services": {"app": {"image": "img:latest", "environment": {"RI_UPDATE_SECRET": "s3"}}}}), ""
            if sub[:1] == ["pull"]:
                if self.fail == "pull":
                    return 1, "", "network down"
                self.images["img:latest"] = self.pulled
                self.images[self.pulled[0]] = self.pulled
                return 0, "", ""
            if sub[:1] == ["ps"]:
                return 0, "cid1\n", ""
            if sub[:1] == ["down"]:
                return (1, "", "boom") if self.fail == "down" else (0, "", "")
            if sub[:1] == ["up"]:
                self.running = self.images["img:latest"]
                return 0, "", ""
        if args[:2] == ["image", "inspect"]:
            ident, ver = self.images[args[2]]
            env = [f"RI_VERSION={ver}"] if ver else []
            return 0, f"{ident}|{json.dumps(env)}", ""
        if args[:1] == ["inspect"]:
            return 0, self.running[0] + "\n", ""
        raise AssertionError(cmd)

    def updater(self):
        u = ri.Updater("/x/compose.yaml", "http://app", runner=self.runner, sleep=lambda s: None)
        u.secret = "s3"
        u.report = lambda state, message=None, current=None, available=None, retry=False: self.reports.append((state, current, available))
        u.wait_healthy = lambda: self.app_up
        return u

    def steps(self):
        return [c[4] for c in self.calls if c[1] == "compose" and len(c) > 4]


class UpdaterTest(unittest.TestCase):
    def test_version_key(self):
        self.assertGreater(ri.version_key("1.10.0"), ri.version_key("1.9.9"))
        self.assertEqual(ri.version_key("v1.4.0-rc1"), (1, 4, 0))
        self.assertIsNone(ri.version_key(None))
        self.assertIsNone(ri.version_key("latest"))

    def test_secret_comes_from_compose_config(self):
        f = Fake()
        u = ri.Updater("/x/c.yaml", "http://app", runner=f.runner)
        self.assertEqual(u.load_secret(), "s3")

    def test_check_reports_available_and_does_not_restart(self):
        f = Fake()
        f.updater().do_check()
        self.assertEqual(f.reports[-1], ("done", "1.3.0", "1.4.0"))
        self.assertNotIn("down", f.steps())
        self.assertNotIn("up", f.steps())

    def test_check_same_version_says_newest(self):
        f = Fake(pulled=("sha256:old", "1.3.0"))
        f.updater().do_check()
        self.assertEqual(f.reports[-1], ("done", "1.3.0", None))

    def test_update_happy_path_order(self):
        f = Fake()
        f.updater().do_update()
        self.assertEqual([r[0] for r in f.reports], ["pulling", "restarting", "done"])
        self.assertEqual(f.reports[-1][1], "1.4.0")
        s = [x for x in f.steps() if x in ("pull", "down", "up")]
        self.assertEqual(s, ["pull", "down", "up"])

    def test_update_with_nothing_new_does_not_restart(self):
        f = Fake(pulled=("sha256:old", "1.3.0"))
        f.updater().do_update()
        self.assertEqual(f.reports[-1][0], "done")
        self.assertNotIn("down", f.steps())

    def test_never_goes_backwards_or_installs_unversioned(self):
        for pulled in (("sha256:older", "1.2.0"), ("sha256:none", None)):
            f = Fake(pulled=pulled)
            f.updater().do_update()
            self.assertEqual(f.reports[-1][0], "done")
            self.assertNotIn("down", f.steps())

    def test_pull_failure_reports_failed(self):
        f = Fake(fail="pull")
        f.updater().do_update()
        self.assertEqual(f.reports[-1][0], "failed")
        self.assertNotIn("down", f.steps())

    def test_down_failure_reports_failed_without_up(self):
        f = Fake(fail="down")
        f.updater().do_update()
        self.assertEqual(f.reports[-1][0], "failed")
        self.assertNotIn("up", f.steps())

    def test_unhealthy_after_up_reports_failed(self):
        f = Fake()
        f.app_up = False
        f.updater().do_update()
        self.assertEqual(f.reports[-1][0], "failed")

    def test_never_uses_down_volumes(self):
        f = Fake()
        f.updater().do_update()
        self.assertFalse(any("-v" in c or "--volumes" in c for c in f.calls))

    def test_poll_runs_the_requested_action_only(self):
        f = Fake()
        u = f.updater()
        for action, expect in (("check", "do_check"), ("update", "do_update"), (None, None)):
            called = []
            u.call = lambda m, p, b=None, a=action: {"action": a}
            u.do_check = lambda: called.append("do_check")
            u.do_update = lambda: called.append("do_update")
            u.poll_once()
            self.assertEqual(called, [expect] if expect else [])


if __name__ == "__main__":
    unittest.main()
