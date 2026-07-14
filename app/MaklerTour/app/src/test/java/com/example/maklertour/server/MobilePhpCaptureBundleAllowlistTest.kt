package com.maklertour.server

import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

class MobilePhpCaptureBundleAllowlistTest {
    @Test
    fun mobilePhpAllowsAutoPhotoSessionBundles() {
        val root = generateSequence(File(System.getProperty("user.dir"))) { it.parentFile }
            .first { File(it, "../web/www/api/mobile.php").exists() || File(it, "web/www/api/mobile.php").exists() }
        val mobilePhp = listOf(File(root, "../web/www/api/mobile.php"), File(root, "web/www/api/mobile.php")).first { it.exists() }
        assertTrue(mobilePhp.readText().contains("auto_photo_session"))
    }
}
