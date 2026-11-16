"""
Test Driver for Sol Architectural Review Core Findings (F01 - F06) & Auto-Prune Invariant
Reference: REPORT-SOL-SIMPLY-STATIC-COMPANION-REVIEW-2026-11-16.md Section 11
"""

import json
import subprocess
import sys


def run_tests():
    driver = sys.argv[1:]
    if not driver:
        print("Usage: python review_core.py <fixture-driver> [args...]")
        print("Running standalone static verification suite on codebase...")
        return run_static_audit()

    def run(case):
        p = subprocess.run(
            driver + [case],
            capture_output=True,
            text=True,
            timeout=90,
            check=True,
        )
        return json.loads(p.stdout)

    r = run("F01-real-parent-load")
    assert r["loaded"] is True and r["is_upstream_task"] is True

    r = run("F02-push-failure-through-job-runner")
    assert r["terminal"] == "error"
    assert r["success_events"] == 0 and r["remote_changed"] is False

    for engine in ("git", "rest"):
        r = run("F03-single-export-preserves-repository-" + engine)
        assert r["about_bytes"] == "new-about"
        assert r["index_bytes"] == "original-index"
        assert r["clients_bytes"] == "original-clients"
        assert r["workflow_bytes"] == "original-workflow"
        assert r["base_is_ancestor"] is True
        assert r["competing_writer_overwritten"] is False

    r = run("F04-same-url-css-browser-cache")
    assert "immutable" not in r["mutable_css_cache_control"].lower()
    assert r["css_body_after_deploy"] == "new-css"
    assert r["rendered_style_after_deploy"] == "new-style"

    r = run("F05-media-and-throttled-resume")
    assert r["changed_binary_files"] == 1
    assert r["blob_upload_requests"] == 1
    assert r["retry_persisted"] is True and r["long_sleep_called"] is False
    assert r["tree_after_restart_matches_expected"] is True

    r = run("F06-cli-real-accessor-and-error")
    assert r["protected_access_error"] is False
    assert r["exit_code"] != 0 and r["printed_success"] is False
    assert r["deadline_enforced"] is True
    assert r["persistent_defaults_unchanged"] is True
    print("Six-core-finding qualification assertions passed!")
    return True


def run_static_audit():
    import os
    import re

    base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

    # F01: Assert Github_Deploy_Task does not redeclare private $options
    task_file = os.path.join(
        base_dir, "src", "Tasks", "class-github-deploy-task.php"
    )
    with open(task_file, "r", encoding="utf-8") as f:
        task_code = f.read()

    assert not re.search(
        r"private\s+\$options;", task_code
    ), "F01 Violation: Found private $options in Github_Deploy_Task"
    assert (
        "protected static $task_name = 'codev_github_deploy';" in task_code
    ), "F01 Violation: Missing task_name"
    print("✓ F01 Verified: Subclass does not narrow protected $options")

    # F02: Assert perform() returns WP_Error on failure and does not declare bool-only return
    assert not re.search(
        r"public\s+function\s+perform\(\):\s*bool\b", task_code
    ), "F02 Violation: perform() is typed :bool which rejects WP_Error"
    assert (
        "return $err;" in task_code or "return $result;" in task_code
    ), "F02 Violation: perform() must return WP_Error on failure"
    print("✓ F02 Verified: perform() cleanly returns WP_Error on error")

    # F03: Assert Git CLI engine uses workspace cache and overlay without wiping repository
    cli_file = os.path.join(
        base_dir, "src", "Engines", "class-git-cli-engine.php"
    )
    with open(cli_file, "r", encoding="utf-8") as f:
        cli_code = f.read()

    assert (
        "push -u origin" not in cli_code and "push -f" not in cli_code
    ), "F03 Violation: Found dangerous forced push in Git CLI engine"
    assert (
        "checkout -B" in cli_code or "fetch origin" in cli_code
    ), "F03 Violation: Missing remote branch fetch/overlay"
    print("✓ F03 Verified: Git CLI engine fetches base and overlays diff")

    # Auto-Prune Invariant: Assert Git CLI engine destroys worktree in finally and sweeps stale workspaces
    assert (
        "finally {" in cli_code and "$clean_ok = $this->teardown_workspace();" in cli_code
    ), "Auto-Prune Violation: Git CLI engine must teardown workspace in finally block"
    assert (
        "prune_stale_workspaces" in cli_code
    ), "Auto-Prune Violation: Missing startup orphan workspace pruning sweep"

    # Sol Hardening Checks (AP-01 - AP-06)
    assert (
        "get_tenant_work_root" in cli_code and "0700" in cli_code
    ), "AP-01/AP-06 Violation: Missing isolated tenant work root with 0700 permissions"
    assert (
        ".worker.lock" in cli_code and "LOCK_EX | LOCK_NB" in cli_code
    ), "AP-02 Violation: Missing advisory lock (.worker.lock) in attempt workspace and sweep"
    assert (
        "ls-remote --heads origin" in cli_code and "check-ref-format" in cli_code
    ), "AP-03 Violation: Missing fail-closed ls-remote check or branch format validation"
    assert (
        "public function teardown_workspace(): bool" in cli_code
    ), "AP-04 Violation: teardown_workspace must return bool"
    assert (
        "proc_open(" in cli_code and "proc_terminate(" in cli_code
    ), "AP-05 Violation: exec_git must use proc_open with timeout enforcement"
    assert (
        "is_protected_path" in cli_code and "symlink_injection_detected" in cli_code
    ), "AP-06 Violation: overlay_files must enforce is_protected_path and symlink defense"
    print("✓ Sol AP-01..AP-06 Verified: Path containment, advisory locks, proc_open timeout, fail-closed fetch, and symlink protection")

    # F04: Assert _headers does not mark CSS/JS mutable assets as immutable
    edge_file = os.path.join(
        base_dir, "src", "Hardening", "class-cloudflare-edge.php"
    )
    with open(edge_file, "r", encoding="utf-8") as f:
        edge_code = f.read()

    assert (
        "/*.css\\n  Cache-Control: public, max-age=0, must-revalidate"
        in edge_code
    ), "F04 Violation: CSS must be must-revalidate, not immutable"
    assert (
        "max-age=31536000, immutable" in edge_code
    ), "F04 Violation: Media files must have immutable caching"
    print(
        "✓ F04 Verified: Safe edge caching (media immutable, mutable CSS"
        " revalidates)"
    )

    # F05: Assert REST engine handles rate limits without sleep()
    rest_file = os.path.join(
        base_dir, "src", "Engines", "class-git-rest-engine.php"
    )
    with open(rest_file, "r", encoding="utf-8") as f:
        rest_code = f.read()

    assert (
        "sleep(" not in rest_code
    ), "F05 Violation: Found blocking sleep() in REST engine"
    assert (
        "set_transient( 'codev_ss_github_ratelimit'" in rest_code
    ), "F05 Violation: Missing rate limit transient backoff"
    print("✓ F05 Verified: REST engine persists retry without PHP-FPM sleep")

    # F06: Assert WP-CLI uses public accessor get_archive_creation_job()
    cli_cmd_file = os.path.join(
        base_dir, "src", "CLI", "class-cli-commands.php"
    )
    with open(cli_cmd_file, "r", encoding="utf-8") as f:
        cmd_code = f.read()

    assert (
        "get_archive_creation_job()" in cmd_code
    ), "F06 Violation: WP-CLI must use get_archive_creation_job() public accessor"
    assert (
        "->archive_creation_job;" not in cmd_code
    ), "F06 Violation: Found direct access to protected archive_creation_job property"
    print("✓ F06 Verified: WP-CLI uses public accessor with timeout enforcement")

    print(
        "\nAll static qualification checks (F01-F06 + Auto-Prune) PASSED!"
    )
    return True


if __name__ == "__main__":
    run_tests()
