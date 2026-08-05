package com.maklertour.data.dualphone

/**
 * Top-level operating mode selected by the operator.
 *
 * [DualPhoneRole] remains a compatibility detail for the existing phone-to-phone
 * runtimes until LM02.7B.5.4.5 removes the laptop dependency on the SLAVE role.
 */
enum class ApplicationCaptureMode {
    STANDALONE_COLMAP,
    DUAL_PHONE_MASTER,
    DUAL_PHONE_SLAVE,
    LAPTOP_STEREO_CLIENT,
    PHONE_USB_STEREO;

    val compatibilityRole: DualPhoneRole
        get() = when (this) {
            STANDALONE_COLMAP, PHONE_USB_STEREO ->
                DualPhoneRole.STANDALONE
            DUAL_PHONE_MASTER -> DualPhoneRole.MASTER
            DUAL_PHONE_SLAVE, LAPTOP_STEREO_CLIENT ->
                DualPhoneRole.SLAVE
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
