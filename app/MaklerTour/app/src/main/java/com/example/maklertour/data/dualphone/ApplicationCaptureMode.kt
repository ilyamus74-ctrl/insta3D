package com.maklertour.data.dualphone

/**
 * Top-level operating mode selected by the operator.
 *
 * [DualPhoneRole] is now restricted to the phone-to-phone MASTER/SLAVE control
 * channel. Laptop capture is a separate application mode and persists the neutral
 * STANDALONE compatibility role.
 */
enum class ApplicationCaptureMode {
    STANDALONE_COLMAP,
    DUAL_PHONE_MASTER,
    DUAL_PHONE_SLAVE,
    LAPTOP_STEREO_CLIENT,
    PHONE_USB_STEREO;

    val compatibilityRole: DualPhoneRole
        get() = when (this) {
            STANDALONE_COLMAP, LAPTOP_STEREO_CLIENT, PHONE_USB_STEREO ->
                DualPhoneRole.STANDALONE
            DUAL_PHONE_MASTER -> DualPhoneRole.MASTER
            DUAL_PHONE_SLAVE -> DualPhoneRole.SLAVE
        }

    companion object {
        fun migrateFromLegacyRole(role: DualPhoneRole): ApplicationCaptureMode =
            when (role) {
                DualPhoneRole.STANDALONE -> STANDALONE_COLMAP
                DualPhoneRole.MASTER -> DUAL_PHONE_MASTER
                DualPhoneRole.SLAVE -> DUAL_PHONE_SLAVE
            }
    }
}
