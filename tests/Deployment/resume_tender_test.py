#!/usr/bin/env python3
"""Recovery tests with real disposable Git/backups and simulated PHP/HTTP."""
import json
import subprocess
import unittest
import deploy_script_test as deployment

PROJECT = deployment.PROJECT
SHIM = deployment.SHIM


class TenderRecoveryTest(unittest.TestCase):
    def setUp(self):
        deployment.DeploymentScriptTest.setUpClass()
        self.f = deployment.DeploymentScriptTest()
        self.f.setUp()
        self.addCleanup(self.f.doCleanups)
        migration = 'migrations/Version20261023010000.php'
        self.f.put(migration, (PROJECT / migration).read_text())
        self.f.git('add', migration, cwd=self.f.source)
        self.f.git('commit', '-m', 'Preparation migration', cwd=self.f.source)
        self.f.git('push', 'origin', 'main', cwd=self.f.source)
        result = self.f.deploy('unknown_migration')
        self.assertNotEqual(0, result.returncode)
        self.backup = self.f.runs[0]
        log = self.backup / 'deployment.log'
        log.write_text(log.read_text().replace('Migration en attente non autorisée', 'Migration en attente non autorisée (DoctrineMigrations\\Version20261023010000)'))
        shim = SHIM.replace('== "database-helper.php":', 'in ("database-helper.php", "deploy-database.php"):')
        shim = shim.replace('if mode == "unknown_migration":', '''if pathlib.Path(args[2]).name == "resume-migrations.allowlist":
                calls = [json.loads(line) for line in pathlib.Path(os.environ["DEPLOY_TEST_CALLS"]).read_text().splitlines()]
                executed = any("DoctrineMigrations\\\\Version20261023010000" in c and "doctrine:migrations:execute" in c and "--dry-run" not in c for c in calls)
                if not executed: print("DoctrineMigrations\\\\Version20261023010000")
                sys.exit(0)
            if mode == "unknown_migration":''')
        (self.f.mockbin / 'php').write_text(shim)

    def resume(self, mode=''):
        self.f.env['DEPLOY_TEST_FAILURE'] = mode
        return subprocess.run(['bash', str(PROJECT / 'scripts/resume-tender-deployment.sh'), str(self.backup)], env=self.f.env, text=True, capture_output=True, timeout=30)

    def test_success_executes_only_reviewed_migration_and_reopens_after_checks(self):
        result = self.resume()
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual('SUCCESS', (self.backup / 'status').read_text().strip())
        self.assertFalse((self.f.app / 'var/deployment-maintenance.json').exists())
        calls = [json.loads(line) for line in self.f.calls_file.read_text().splitlines()]
        executions = [c for c in calls if 'doctrine:migrations:execute' in c]
        self.assertEqual(2, len(executions))
        self.assertTrue(all('DoctrineMigrations\\Version20261023010000' in c for c in executions))
        self.assertIn('--dry-run', executions[0])

    def test_failed_health_keeps_maintenance_and_can_be_retried(self):
        result = self.resume('health')
        self.assertNotEqual(0, result.returncode)
        self.assertTrue((self.f.app / 'var/deployment-maintenance.json').exists())
        self.assertIn('[R=503,L]', (self.f.app / 'public/.htaccess').read_text())
        result = self.resume()
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_modified_code_is_refused(self):
        (self.f.app / 'version.txt').write_text('unexpected change')
        result = self.resume()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('Modifications locales', result.stderr)
        self.assertTrue((self.f.app / 'var/deployment-maintenance.json').exists())

    def test_failed_migration_keeps_maintenance(self):
        result = self.resume('migration')
        self.assertNotEqual(0, result.returncode)
        self.assertTrue((self.f.app / 'var/deployment-maintenance.json').exists())
        self.assertIn('[R=503,L]', (self.f.app / 'public/.htaccess').read_text())


if __name__ == '__main__':
    unittest.main(verbosity=2)
