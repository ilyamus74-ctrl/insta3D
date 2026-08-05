package com.maklertour.data.dualphone

/**
 * Top-level operating mode selected by the operator.
 *
 * [DualPhoneRole] is derived only for the direct phone-to-phone control channel.
 * Laptop and phone+USB capture never borrow MASTER/SLAVE semantics.
 */
enum class ApplicationCaptureMode {
    STANDALONE_COLMAP,
    DUAL_PHONE_MASTER,
    DUAL_PHONE_SLAVE,
    LAPTOP_STEREO_CLIENT,
    PHONE_USB_STEREO;

    val phoneToPhoneRoleOrNull: DualPhoneRole?
        get() = when (this) {
            DUAL_PHONE_MASTER -> DualPhoneRole.MASTER
            DUAL_PHONE_SLAVE -> DualPhoneRole.SLAVE
            STANDALONE_COLMAP,
            LAPTOP_STEREO_CLIENT,
            PHONE_USB_STEREO -> null
        }

    companion object {
        /** One-time migration source for installations created before schema 7. */
        fun migrateFromLegacyRole(role: DualPhoneRole): ApplicationCaptureMode =
            when (role) {
                DualPhoneRole.STANDALONE -> STANDALONE_COLMAP
                DualPhoneRole.MASTER -> DUAL_PHONE_MASTER
                DualPhoneRole.SLAVE -> DUAL_PHONE_SLAVE
            }
    }
}
