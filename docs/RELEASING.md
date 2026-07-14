# Releasing Talk AI

Publishing a GitHub release triggers `.github/workflows/publish-release.yml`. The workflow checks out the released tag, validates that the tag matches the version in `appinfo/info.xml`, builds the frontend and production Composer dependencies, creates the installable tarball, attaches it to the GitHub release, signs it and publishes it to the existing Nextcloud App Store entry.

## Repository secrets

The workflow requires these GitHub Actions repository secrets:

- `APPSTORE_TOKEN`: API token from <https://apps.nextcloud.com/account/token>
- `APP_PRIVATE_KEY`: private signing key for the `educai` app ID
- `APP_PUBLIC_CRT`: Nextcloud-issued certificate matching the private key

The workflow verifies that the key and certificate match before uploading anything. Secret values must never be committed to the repository.

## Normal release

1. Update `<version>` in `appinfo/info.xml` and commit the release changes.
2. Create and publish a GitHub release with the matching tag, for example `v2.40.0` for app version `2.40.0`.
3. The workflow builds `educai-2.40.0.tar.gz`, replaces any asset with that name on the GitHub release, verifies the public download and submits the release to the Nextcloud App Store.

The workflow rejects a release when the tag and app version differ.

## Existing release or retry

Run **Build and publish app release** manually from the Actions tab and enter the existing release tag. Disable **Publish the built release to the Nextcloud App Store** to test only the build and GitHub asset upload. Leave it enabled to retry the complete App Store publication.
