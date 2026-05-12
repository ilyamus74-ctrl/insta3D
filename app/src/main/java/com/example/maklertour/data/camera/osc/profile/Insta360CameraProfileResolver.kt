package com.maklertour.data.camera.osc.profile

class Insta360CameraProfileResolver(
    private val x4Profile: Insta360CameraProfile = Insta360X4OscProfile(),
    private val genericProfile: Insta360CameraProfile = GenericInsta360OscProfile(),
) {
    fun resolve(model: String?): Insta360CameraProfile {
        val normalizedModel = model?.trim().orEmpty()
        return if (x4Profile.supports(normalizedModel)) x4Profile else genericProfile
    }
}
