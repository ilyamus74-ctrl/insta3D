package com.maklertour.data.phonecamera

import android.content.Context
import java.io.File

class ImuRecorder(private val context: Context) {
    private var outputFile: File? = null

    fun start(sessionId: String, scanId: String, dir: File) {
        dir.mkdirs()
        outputFile = File(dir, "imu.jsonl")
        outputFile?.writeText("")
    }

    fun stop() {
        outputFile = null
    }
}
