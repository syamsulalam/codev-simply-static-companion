# Codev Simply Static Companion (`codev-simply-static-companion`)

Sovereign high-performance GitHub and Cloudflare Pages deployment companion for **Simply Static Free**. Replaces proprietary Simply Static Pro by leveraging native Git CLI transport, Cloudflare Pages edge hardening, dynamic asset sniffing, and selective single-post incremental exports.

---

## Features

- **Native Git CLI Transport (Primary):** Executes atomic diff commits and fast-forward pushes in <3s. Zero GitHub API rate-limit calls. Ephemeral in-memory token injection (`-c http.extraheader`) ensures GitHub PAT is never persisted in `.git/config` or logged.
- **Git REST Database API (Fallback):** Pure PHP fallback for environments without `exec()` or Git CLI. Inlines small UTF-8 text files (<60 KB) into tree chunks, reuses existing blob SHAs for unchanged assets, and persists backoff deadlines on rate limits without calling `sleep()` in PHP-FPM workers.
- **Native React UI Unlock:** Unlocks Simply Static Free's built-in GitHub configuration tabs (`$args['plan'] = 'pro'`).
- **Cloudflare Pages Edge Hardening:** Automatically generates root `404.html` (preventing SPA fallback traps), safe `_headers` (1-year immutable caching for static media, `must-revalidate` for mutable CSS/JS/HTML), and native `_redirects` rules.
- **Dynamic CSS & Asset Sniffer:** Automatically discovers and injects Elementor dynamic post CSS (`post-{id}.css`) and Spectra (`uag-plugin`) block assets into the export manifest so static sites never lose their styles.
- **Selective Single-Post Auto-Export:** Coalesces rapid post saves via `wp_after_insert_post`, crawls only affected URLs (post, archives, homepage), and deploys incremental diffs without wiping unrelated pages.
- **WP-CLI Fleet Automation:** `wp simply-static-codev export-and-deploy`, `wp simply-static-codev single <id>`, and `wp simply-static-codev status` with verified run ID binding, timeout deadlines, and strict non-zero exit codes on failure.
- **Post-Commit Verified Notifications:** Dispatches webhooks with SSRF defense (HTTPS-only, RFC 1918 / loopback blocks) and optional GitHub repository dispatches only after the commit SHA is verified on remote.

---

## Directory Structure

```
codev-simply-static-companion/
├── codev-simply-static-companion.php  # Main plugin header & PSR-4 autoloader
├── src/
│   ├── class-plugin.php               # Core singleton, hooks registration & UI unlock
│   ├── Tasks/
│   │   └── class-github-deploy-task.php   # Simply_Static\Task subclass (inherited $options, WP_Error return)
│   ├── Engines/
│   │   ├── class-publication-plan.php     # Unified publication plan (target, base SHA, changed files)
│   │   ├── class-git-cli-engine.php       # Native Git CLI deployment (workspace cache, base overlay)
│   │   └── class-git-rest-engine.php      # Git Database REST API fallback (media blob reuse, chunking)
│   ├── Hardening/
│   │   ├── class-cloudflare-edge.php      # 404.html, safe _headers, _redirects
│   │   └── class-asset-sniffer.php        # Elementor & Spectra dynamic CSS sniffer
│   ├── Features/
│   │   ├── class-single-export.php        # Coalesced save_post incremental export
│   │   └── class-webhooks.php             # SSRF-protected post-commit notifications
│   └── CLI/
│       └── class-cli-commands.php         # WP-CLI headless driver via get_archive_creation_job()
├── tests/
│   └── review_core.py                 # Qualification driver for Sol findings F01-F06
└── README.md
```

---

## Installation & Fleet Deployment

1. **Golden Master Deployment:**
   Deploy to `/opt/codev/wp-library/plugins/codev-simply-static-companion/` on OracleServer (`wp-oracle`).
2. **Permissions:**
   Set owner to `root:root` with directory permissions `755` and file permissions `644`.
3. **Fleet Symlinking:**
   ```bash
   ln -sfn /opt/codev/wp-library/plugins/codev-simply-static-companion /home/codev-wp*/htdocs/*.codev.id/wp-content/plugins/codev-simply-static-companion
   ```
4. **Activation:**
   ```bash
   sudo -u <site-user> wp --path=/home/<site-user>/htdocs/<domain> plugin activate codev-simply-static-companion
   ```

---

## Qualification & Review Certification

- Certified by Engineer Sol (Codex GPT-6.1 Sol) in `REPORT-SOL-SIMPLY-STATIC-COMPANION-REVIEW-2026-11-16.md`.
- Conforms to Qualification Contract `SS-COMPANION-V1` (Gates G01–G10).
