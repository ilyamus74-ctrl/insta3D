package com.maklertour.data.dualphone

/**
 * Declarative settings-menu visibility for one top-level application mode.
 *
 * Runtime permissions and transport validation remain authoritative. This
 * policy only prevents unrelated settings from being rendered together.
 */
data class ApplicationCaptureModeSettingsVisibility(
    val showStereoRigSettings: Boolean = false,
    val showPhoneRecordingSettings: Boolean = false,
    val showDualPhoneIdentitySettings: Boolean = false,
    val showDualPhoneControlSettings: Boolean = false,
)

val ApplicationCaptureMode.settingsVisibility: ApplicationCaptureModeSettingsVisibility
    get() = when (this) {
        ApplicationCaptureMode.STANDALONE_COLMAP ->
            ApplicationCaptureModeSettingsVisibility(
                showPhoneRecordingSettings = true,
            )
        ApplicationCaptureMode.DUAL_PHONE_MASTER ->
            ApplicationCaptureModeSettingsVisibility(
                showPhoneRecordingSettings = true,
                showDualPhoneIdentitySettings = true,
                showDualPhoneControlSettings = true,
            )
        ApplicationCaptureMode.DUAL_PHONE_SLAVE ->
            ApplicationCaptureModeSettingsVisibility(
                showDualPhoneIdentitySettings = true,
                showDualPhoneControlSettings = true,
            )
        ApplicationCaptureMode.LAPTOP_STEREO_CLIENT ->
            ApplicationCaptureModeSettingsVisibility(
                showPhoneRecordingSettings = true,
                showDualPhoneIdentitySettings = true,
                showDualPhoneControlSettings = true,
            )
        ApplicationCaptureMode.PHONE_USB_STEREO ->
            ApplicationCaptureModeSettingsVisibility(
                showStereoRigSettings = true,
                showPhoneRecordingSettings = true,
            )
    }
