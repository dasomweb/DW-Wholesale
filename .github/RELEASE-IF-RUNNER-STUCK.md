# Release when GitHub Actions runner is stuck

If the "Create Release" workflow stays at "Waiting for a runner", use one of these:

## Option 1: Run the workflow manually

1. Go to **Actions** → **Create Release** → **Run workflow**.
2. Enter the version (e.g. `1.5.0`) in the input. The tag (e.g. `v1.5.0`) must already exist.
3. Click **Run workflow**. When a runner is free, it will create the release and attach the ZIP.

## Option 2: Create the release in the GitHub UI

1. Build the plugin ZIP locally:
   ```powershell
   powershell -ExecutionPolicy Bypass -File build-installable-zip.ps1
   ```
2. Go to **Releases** → **Draft a new release**.
3. Choose the existing tag (e.g. `v1.5.0`) or create it.
4. Set the release title (e.g. `Release v1.5.0`).
5. Upload the ZIP from the project root (e.g. `dw-wholesale-1.5.6.zip` — filename is versioned; install folder is always `dw-wholesale`).
6. Publish the release.

The plugin ZIP is the same whether created by Actions or by the local script: versioned filename, single root folder `dw-wholesale/` so the install path is always `wp-content/plugins/dw-wholesale/`.
