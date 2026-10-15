#!/usr/bin/env python3
"""Exercise deploy.sh in disposable Git repositories, without network or a database.

Real Git, tar, gzip and rsync exercise archive/install behaviour. PHP, Composer,
HTTP, SQL dumps, flock, sleep and free-space checks are explicit local test doubles.
HOME is never changed and the deployment state/backups stay in TemporaryDirectory.
Run: python3 tests/Deployment/deploy_script_test.py
"""

from __future__ import annotations

import gzip
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest


PROJECT = Path(__file__).resolve().parents[2]
MOCKED_COMMANDS = ("php", "composer", "curl", "mysqldump", "mariadb-dump", "flock", "sleep", "df")

SHIM = r'''#!/usr/bin/env python3
import hashlib, json, os, pathlib, sys
name = pathlib.Path(sys.argv[0]).name
args = sys.argv[1:]
mode = os.environ.get("DEPLOY_TEST_FAILURE", "")
with open(os.environ["DEPLOY_TEST_CALLS"], "a") as log:
    log.write(json.dumps([name, *args]) + "\n")
def fail(message):
    print(message, file=sys.stderr)
    sys.exit(1)
if name == "php":
    if args[0] == "-r":
        if "random_bytes" in args[1]: print("a" * 64, end="")
        elif "stream_get_contents" in args[1]:
            print(json.dumps({"probe_hash": hashlib.sha256(sys.stdin.buffer.read()).hexdigest()}), end="")
        elif "PHP_VERSION_ID" not in args[1]: fail("Unexpected PHP expression")
    elif pathlib.Path(args[0]).name == "database-helper.php":
        action = args[1]
        if action == "database-name": print("fixture_database")
        elif action == "database-size": print("1048576")
        elif action == "configuration-fingerprint":
            count = sum("configuration-fingerprint" in json.loads(line) for line in pathlib.Path(os.environ["DEPLOY_TEST_CALLS"]).read_text().splitlines())
            print(("e" if mode == "configuration" and count > 1 else "f") * 64)
        elif action == "dump-config":
            path = pathlib.Path(args[2]); path.write_text('[client]\npassword="fixture-secret"\n'); path.chmod(0o600)
        elif action == "pending-migrations":
            if mode == "unknown_migration": fail("Migration en attente non autorisée")
            print("DoctrineMigrations\\Version20261016010000")
            print("DoctrineMigrations\\Version20261016011000")
        elif action != "preflight": fail("Unexpected database action")
    elif args[0] == "bin/console":
        if args[1] == "doctrine:migrations:execute" and "--dry-run" not in args and mode == "migration":
            fail("Simulated migration failure")
    else: fail("Unexpected PHP invocation")
elif name == "composer":
    if args[0] == "install" and mode == "composer": fail("Simulated Composer failure")
    if args[0] == "install" and mode == "installed_dirty": pathlib.Path("version.txt").write_text("unexpected installed change\n")
    if args[0] == "dump-env": pathlib.Path(".env.local.php").write_text("<?php return [];\n")
elif name in ("mysqldump", "mariadb-dump"):
    if "--help" in args: print("--set-gtid-purged")
    else:
        print("-- deterministic local fixture")
        if mode == "dump": fail("Simulated dump failure")
        print("CREATE TABLE fixture (id INT PRIMARY KEY);\nINSERT INTO fixture VALUES (1);")
elif name == "curl":
    if "-w" in args:
        maintenance = pathlib.Path(os.environ["PHILIP_DEPLOY_APP_DIR"]) / "var/deployment-maintenance.json"
        if "-o" in args and args[args.index("-o") + 1] != "/dev/null":
            pathlib.Path(args[args.index("-o") + 1]).write_text('<input name="_username"><link href="/css/auth.css">')
        asset = args[-1].endswith(("/css/auth.css", "/js/auth.js"))
        status = "200" if mode == "maintenance" or asset or not maintenance.exists() else "503"
        if mode == "reopen" and not maintenance.exists(): status = "500"
        if mode == "asset_redirect" and asset: status = "302"
        print(status, end="")
    else:
        if mode == "health" and "--config" in args: fail("Simulated healthcheck failure")
        if "-D" in args:
            environment = "dev" if mode == "web_environment" else "prod"
            debug = "1" if mode == "web_debug" else "0"
            pathlib.Path(args[args.index("-D") + 1]).write_text("HTTP/2 200\nStrict-Transport-Security: max-age=31536000\nContent-Security-Policy: default-src 'self'\nX-Deployment-Environment: " + environment + "\nX-Deployment-Debug: " + debug + "\n")
        if "-o" in args and args[args.index("-o") + 1] != "/dev/null":
            pathlib.Path(args[args.index("-o") + 1]).write_text('<input name="_username"><link href="/css/auth.css">')
elif name == "flock":
    if mode == "locked": fail("Simulated lock contention")
elif name == "df":
    print("Filesystem 1024-blocks Used Available Capacity Mounted on")
    print("fixture 999999999 1 " + ("1" if mode == "disk" else "999999998") + " 1% /")
elif name != "sleep": fail("Unexpected test command")
'''


class DeploymentScriptTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.geteuid() == 0:
            raise unittest.SkipTest("deploy.sh deliberately refuses root; run these tests as an ordinary user")
        for command in ("git", "bash", "tar", "gzip", "rsync", "sha256sum"):
            if shutil.which(command) is None:
                raise unittest.SkipTest(f"Required real tool absent: {command}")
        # The production launcher prepends HOME/bin. Never allow it to shadow a
        # test double, and never redefine HOME just to work around that behaviour.
        for command in MOCKED_COMMANDS:
            if os.access(Path.home() / "bin" / command, os.X_OK):
                raise unittest.SkipTest(f"HOME/bin/{command} would override an isolated test double")
        if "PHILIP_DEPLOY_STATE_DIR" not in (PROJECT / "deploy.sh").read_text():
            raise unittest.SkipTest("Explicit deployment state override required for isolation")

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="philip-deploy-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        self.origin = self.root / "origin.git"
        self.source = self.root / "source"
        self.app = self.root / "app"
        self.backups = self.root / "backups"
        self.state = self.root / "state"
        self.mockbin = self.root / "mockbin"
        self.mockbin.mkdir()
        for command in MOCKED_COMMANDS:
            script = self.mockbin / command
            script.write_text(SHIM)
            script.chmod(0o700)
        self.calls_file = self.root / "calls.jsonl"
        self.env = os.environ.copy()
        self.env.update({
            "PATH": str(self.mockbin) + os.pathsep + os.environ["PATH"],
            "PHILIP_DEPLOY_APP_DIR": str(self.app),
            "PHILIP_DEPLOY_STATE_DIR": str(self.state),
            "PHILIP_BACKUP_DIR": str(self.backups),
            "DEPLOY_WRITERS_PAUSED": "1",
            "DEPLOY_DRAIN_SECONDS": "30",
            "DEPLOY_TEST_CALLS": str(self.calls_file),
            "DEPLOY_TEST_FAILURE": "",
            "GIT_CONFIG_NOSYSTEM": "1",
            "GIT_CONFIG_GLOBAL": os.devnull,
            "GIT_AUTHOR_NAME": "Deployment fixture",
            "GIT_AUTHOR_EMAIL": "fixture@example.invalid",
            "GIT_COMMITTER_NAME": "Deployment fixture",
            "GIT_COMMITTER_EMAIL": "fixture@example.invalid",
        })
        self.git("init", "--bare", "--initial-branch=main", str(self.origin), cwd=self.root)
        self.git("init", "--initial-branch=main", str(self.source), cwd=self.root)
        self.put(".gitignore", "vendor/\nvar/\n.env.local\n.env.local.php\npublic/uploads/\n")
        self.put("composer.json", '{"name":"fixture/deployment","require":{}}\n')
        self.put("composer.lock", '{"packages":[],"packages-dev":[]}\n')
        self.put("public/index.php", '<?php echo "previous-version";\n')
        self.put("public/.htaccess", "RewriteEngine On\n")
        self.put("version.txt", "previous\n")
        self.put("removed.txt", "obsolete\n")
        self.git("add", ".", cwd=self.source)
        self.git("commit", "-m", "Previous version", cwd=self.source)
        self.before = self.git("rev-parse", "HEAD", cwd=self.source).strip()
        self.git("remote", "add", "origin", str(self.origin), cwd=self.source)
        self.git("push", "origin", "main", cwd=self.source)
        self.git("clone", str(self.origin), str(self.app), cwd=self.root)
        for name, content in {
            "vendor/autoload.php": "<?php // fixture vendor\n",
            ".env.local": "FIXTURE_PRIVATE_CONFIG=preserved\n",
            "public/uploads/private-document.txt": "fixture-upload\n",
            "var/sessions/prod/session-fixture": "fixture-session\n",
            "var/rate_limiter/bucket": "fixture-rate-limit\n",
            "var/cache/prod/old-cache": "excluded-cache\n",
            "var/log/prod.log": "excluded-log\n",
        }.items():
            path = self.app / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)
        self.put("version.txt", "candidate\n")
        (self.source / "removed.txt").unlink()
        self.put("public/index.php", "<?php require dirname(__DIR__).'/scripts/deploy-maintenance.php';\n")
        self.put("public/.htaccess", "RewriteEngine On\n# candidate\n")
        self.put("deploy/migrations.allowlist", "DoctrineMigrations\\Version20261016010000\nDoctrineMigrations\\Version20261016011000\n")
        for name in ("deploy.sh", "scripts/deploy-database.php", "scripts/deploy-maintenance.php"):
            self.put(name, (PROJECT / name).read_text())
        self.git("add", "-A", cwd=self.source)
        self.git("commit", "-m", "Candidate version", cwd=self.source)
        self.target = self.git("rev-parse", "HEAD", cwd=self.source).strip()
        self.git("push", "origin", "main", cwd=self.source)
        self.launcher = self.root / "deploy.sh"
        shutil.copy2(PROJECT / "deploy.sh", self.launcher)

    def put(self, relative, content):
        path = self.source / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)

    def git(self, *args, cwd):
        return subprocess.run(["git", *args], cwd=cwd, env=self.env, text=True,
                              capture_output=True, check=True).stdout

    def deploy(self, failure="", check=False):
        self.env["DEPLOY_TEST_FAILURE"] = failure
        result = subprocess.run(["bash", str(self.launcher), *(["--check"] if check else [])],
                                cwd=self.root, env=self.env, text=True,
                                capture_output=True, timeout=30)
        self.output = result.stdout + result.stderr
        self.calls = [json.loads(line) for line in self.calls_file.read_text().splitlines()]
        self.runs = sorted(self.backups.glob("*-*")) if self.backups.exists() else []
        return result

    def called(self, command, arg=None):
        return any(call[0] == command and (arg is None or arg in call[1:]) for call in self.calls)

    def assertNoMigration(self):
        self.assertFalse(self.called("php", "doctrine:migrations:execute"), self.output)

    def assertMaintenance(self, active):
        self.assertEqual(active, (self.app / "var/deployment-maintenance.json").exists(), self.output)

    def assertCommit(self, expected):
        self.assertEqual(expected, self.git("rev-parse", "HEAD", cwd=self.app).strip(), self.output)

    def assertVerifiedBackup(self):
        self.assertEqual(1, len(self.runs), self.output)
        backup = self.runs[0]
        self.assertTrue((backup / "database.sql.gz").exists(), self.output)
        self.assertIn("CREATE TABLE fixture", gzip.decompress((backup / "database.sql.gz").read_bytes()).decode())
        self.assertFalse((backup / "mysql.cnf").exists())
        self.assertFalse((backup / "probe.curl").exists())
        for line in (backup / "SHA256SUMS").read_text().splitlines():
            digest, name = line.split(maxsplit=1)
            self.assertEqual(digest, hashlib.sha256((backup / name.strip().lstrip("*")).read_bytes()).hexdigest())
        with tarfile.open(backup / "application.tar.gz") as archive:
            names = {name.removeprefix("./") for name in archive.getnames()}
            for name in ("vendor/autoload.php", ".env.local", "public/uploads/private-document.txt",
                         "var/sessions/prod/session-fixture", "var/rate_limiter/bucket", "version.txt"):
                self.assertIn(name, names)
            self.assertNotIn("var/cache/prod/old-cache", names)
            self.assertNotIn("var/log/prod.log", names)
            self.assertNotIn("var/deployment-maintenance.json", names)
            self.assertEqual(b"previous\n", archive.extractfile("./version.txt").read())
        self.assertIn("previous-version", (backup / "original-public/index.php").read_text())
        self.assertEqual(0o700, backup.stat().st_mode & 0o777)

    def test_success_retains_verified_private_backup_and_deploys_exact_commit(self):
        result = self.deploy()
        self.assertEqual(0, result.returncode, self.output)
        self.assertVerifiedBackup()
        self.assertEqual("SUCCESS\n", (self.runs[0] / "status").read_text())
        self.assertCommit(self.target)
        self.assertMaintenance(False)
        self.assertFalse((self.app / "removed.txt").exists())
        self.assertEqual("fixture-upload\n", (self.app / "public/uploads/private-document.txt").read_text())
        self.assertEqual("fixture-session\n", (self.app / "var/sessions/prod/session-fixture").read_text())
        self.assertEqual("fixture-rate-limit\n", (self.app / "var/rate_limiter/bucket").read_text())
        self.assertEqual("", self.git("status", "--porcelain", cwd=self.app))
        migrations = [call for call in self.calls if "doctrine:migrations:execute" in call]
        self.assertEqual(4, len(migrations))
        self.assertTrue(all("--dry-run" in call for call in migrations[:2]))
        self.assertTrue(all("--dry-run" not in call for call in migrations[2:]))
        install = next(call for call in self.calls if call[:2] == ["composer", "install"])
        self.assertIn("--no-scripts", install)
        self.assertIn("--no-dev", install)

    def test_check_is_read_only_for_code_database_and_maintenance(self):
        result = self.deploy(check=True)
        self.assertEqual(0, result.returncode, self.output)
        self.assertCommit(self.before)
        self.assertMaintenance(False)
        self.assertNoMigration()
        self.assertFalse(self.called("composer", "install"))
        self.assertFalse(self.called("mariadb-dump"))
        self.assertEqual("CHECKED\n", (self.runs[0] / "status").read_text())

    def test_dump_failure_preserves_maintenance_and_never_installs_candidate(self):
        result = self.deploy("dump")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertCommit(self.before)
        self.assertMaintenance(True)
        self.assertNoMigration()
        self.assertFalse(self.called("composer", "install"))
        self.assertFalse((self.runs[0] / "database.sql.gz").exists())
        self.assertFalse((self.runs[0] / "mysql.cnf").exists())
        self.assertEqual("FAILED\n", (self.runs[0] / "status").read_text())

    def test_composer_failure_preserves_maintenance_and_backup(self):
        result = self.deploy("composer")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertVerifiedBackup()
        self.assertCommit(self.target)
        self.assertMaintenance(True)
        self.assertNoMigration()

    def test_migration_failure_preserves_maintenance_and_backup(self):
        result = self.deploy("migration")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertVerifiedBackup()
        self.assertMaintenance(True)
        self.assertTrue(self.called("php", "doctrine:migrations:execute"))
        self.assertFalse(self.called("php", "cache:warmup"))

    def test_unapproved_migration_blocks_all_migration_execution(self):
        result = self.deploy("unknown_migration")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertVerifiedBackup()
        self.assertMaintenance(True)
        self.assertNoMigration()

    def test_dirty_tracked_file_is_never_overwritten(self):
        (self.app / "version.txt").write_text("local work\n")
        result = self.deploy()
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertCommit(self.before)
        self.assertMaintenance(False)
        self.assertEqual("local work\n", (self.app / "version.txt").read_text())
        self.assertFalse(self.runs)

    def test_untracked_file_blocks_without_deleting_it(self):
        path = self.app / "manual-local-file.txt"
        path.write_text("local work\n")
        result = self.deploy()
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertCommit(self.before)
        self.assertMaintenance(False)
        self.assertEqual("local work\n", path.read_text())

    def test_insufficient_disk_blocks_before_maintenance_and_backup(self):
        result = self.deploy("disk")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertCommit(self.before)
        self.assertMaintenance(False)
        self.assertNoMigration()
        self.assertFalse(self.called("composer", "install"))
        self.assertFalse(self.called("mariadb-dump"))

    def test_lock_contention_blocks_before_backup(self):
        result = self.deploy("locked")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertCommit(self.before)
        self.assertMaintenance(False)
        self.assertFalse(self.runs)

    def test_unconfirmed_public_maintenance_never_changes_database_or_code(self):
        result = self.deploy("maintenance")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertCommit(self.before)
        self.assertMaintenance(True)
        self.assertNoMigration()
        self.assertFalse(self.called("composer", "install"))
        self.assertFalse(self.called("mariadb-dump"))

    def test_failed_healthcheck_does_not_reopen_site(self):
        result = self.deploy("health")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertVerifiedBackup()
        self.assertMaintenance(True)
        self.assertIn("scripts/deploy-maintenance.php", (self.app / "public/index.php").read_text())
        self.assertFalse((self.runs[0] / "probe.curl").exists())

    def test_configuration_change_blocks_all_migration_execution(self):
        result = self.deploy("configuration")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertVerifiedBackup()
        self.assertMaintenance(True)
        self.assertNoMigration()

    def test_failed_final_public_check_reinstates_apache_maintenance(self):
        result = self.deploy("reopen")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertVerifiedBackup()
        self.assertMaintenance(True)
        self.assertIn("RewriteRule ^ - [R=503,L]", (self.app / "public/.htaccess").read_text())
        self.assertEqual("FAILED\n", (self.runs[0] / "status").read_text())

    def test_unexpected_installed_changes_block_reopening(self):
        result = self.deploy("installed_dirty")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertVerifiedBackup()
        self.assertMaintenance(True)
        self.assertIn("RewriteRule ^ - [R=503,L]", (self.app / "public/.htaccess").read_text())

    def test_web_development_environment_blocks_reopening(self):
        result = self.deploy("web_environment")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertVerifiedBackup()
        self.assertMaintenance(True)
        self.assertIn("PHP web n’a pas confirmé l’environnement prod", self.output)

    def test_web_debug_enabled_blocks_reopening(self):
        result = self.deploy("web_debug")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertVerifiedBackup()
        self.assertMaintenance(True)
        self.assertIn("PHP web n’a pas confirmé la désactivation du debug", self.output)

    def test_redirecting_asset_blocks_reopening(self):
        result = self.deploy("asset_redirect")
        self.assertNotEqual(0, result.returncode, self.output)
        self.assertVerifiedBackup()
        self.assertMaintenance(True)
        self.assertIn("Ressource publique indisponible : css/auth.css", self.output)


if __name__ == "__main__":
    unittest.main(verbosity=2)
