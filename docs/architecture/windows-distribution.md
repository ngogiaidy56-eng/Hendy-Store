# Windows 10/11 distribution strategy

## 1. Supported installation paths

1. **Microsoft Store listing:** for applications published in Microsoft Store, store the Product ID and offer an explicit `ms-windows-store://pdp/?ProductId=<StoreId>` action.
2. **WinGet package:** for software with a verified WinGet package manifest, show its exact package ID and a user-approved command such as `winget install --id Publisher.Package --exact`.
3. **HendyStore direct package:** offer a signed, hash-verified EXE/MSI/MSIX/ZIP download from the private-origin download flow only after package scanning and publisher review.

Do not trigger WinGet silently from a web page. A browser cannot install a Windows package by itself; the user must choose to open Microsoft Store, run WinGet, or launch the downloaded installer. Silent installation is an explicit WinGet/installer mode used by the user or an administrator, not permission to install without consent.

## 2. Metadata required per Windows release

- Package type: `msix`, `msi`, `exe`, `zip`, `appinstaller` or Store listing.
- Windows minimum version/build and supported architectures (`x64`, `arm64`, `x86`).
- Publisher name and certificate subject/thumbprint when signed.
- SHA-256, file size, version, release notes and scan verdict.
- WinGet package identifier and version, if one exists.
- Microsoft Store Product ID, if one exists.
- Installation instructions, elevation/reboot requirements and uninstall support.

## 3. Recommended product-detail actions

- `Mở Microsoft Store` appears only when a verified Store Product ID is configured.
- `Cài bằng WinGet` appears only when a verified package ID exists; copy the exact command and show it before the user runs it.
- `Tải bản Windows` appears only for a clean, approved package that matches the user's architecture.
- `Kiểm tra SHA-256` displays the checksum and publisher-signature status.

## 4. Microsoft documentation

- [Microsoft Store URI scheme](https://learn.microsoft.com/en-us/windows/apps/develop/launch/launch-store-app)
- [WinGet install command and exact package IDs](https://learn.microsoft.com/en-us/windows/package-manager/winget/install)
- [Microsoft Store Web Installer](https://learn.microsoft.com/en-us/windows/apps/distribute-through-store/how-to-use-store-web-installer-for-distribution)
