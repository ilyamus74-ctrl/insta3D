package com.maklertour.domain

interface CameraProvider {
    suspend fun connect(): CameraStatus
    suspend fun disconnect(): CameraStatus
    suspend fun getStatus(): CameraStatus
    suspend fun capture(pointName: String): CapturePoint
    suspend fun listFiles(): List<String>
}
