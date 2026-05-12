@file:OptIn(androidx.camera.core.ExperimentalGetImage::class)

package com.example.ocrscannertest
import android.content.Context
import java.io.File
import androidx.core.content.FileProvider
import android.provider.MediaStore
import android.content.Intent
import android.os.Build
import android.widget.Toast
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.InputStream
import java.net.HttpURLConnection
import java.net.URL
import android.Manifest
import android.content.pm.PackageManager
import android.os.Bundle
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.LifecycleOwner
import com.example.ocrscannertest.ui.theme.OcrScannerTestTheme
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.common.InputImage
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import androidx.camera.core.*
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import java.util.concurrent.Executors
import javax.net.ssl.HttpsURLConnection
import javax.net.ssl.SSLContext
import javax.net.ssl.TrustManager
import javax.net.ssl.X509TrustManager
import java.security.SecureRandom
import java.security.cert.X509Certificate
import javax.net.ssl.HostnameVerifier
import javax.net.ssl.SSLSession
import android.view.ViewGroup
import android.webkit.WebView
import android.webkit.WebViewClient
import android.app.Activity
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.SslErrorHandler
import android.webkit.WebSettings
import android.webkit.WebStorage
import android.net.http.SslError
import android.webkit.CookieManager
import android.view.KeyEvent
import android.graphics.Rect
import com.google.mlkit.vision.text.TextRecognition
import com.google.mlkit.vision.text.latin.TextRecognizerOptions
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import android.webkit.JavascriptInterface
import android.os.Handler
import android.os.Looper
import android.net.Uri
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.foundation.Image
import androidx.compose.ui.res.painterResource


private val INSTALL_MAIN_OBSERVER_JS = """
(function(){
  if (window.__deviceMainObserverInstalled) return;
  window.__deviceMainObserverInstalled = true;

function read(id){
  var el = document.getElementById(id);
  if(!el) return null;
  var t = "";
  if (el.tagName === "INPUT" || el.tagName === "TEXTAREA") {
    t = el.value;
  } else {
    t = el.textContent || el.innerText || "";
  }
  t = (t || "").trim();
  if (!t || t === "null" || t === "undefined") return null;
  return t;
}

  function emit(){
    try{
      var payload = {
        task: read("device-scan-config"),
        ocr_templates: read("ocr-templates"),
        destcountry: read("ocr-templates-destcountry"),
        dicts: read("ocr-dicts")
      };
      if (window.DeviceApp && window.DeviceApp.onMainContext) {
        window.DeviceApp.onMainContext(JSON.stringify(payload));
      }
    } catch(e){}
  }

  var t=null;
  function schedule(){ clearTimeout(t); t=setTimeout(emit,120); }

var root = document.getElementById("main") || document.body;
if (root) new MutationObserver(schedule).observe(root, {childList:true, subtree:true});


  emit();
})();
""".trimIndent()

enum class WarehouseScanStep { BARCODE, OCR, MEASURE, SUBMIT }

private const val VOLUME_DOUBLE_TAP_WINDOW_MS = 650L
private var debugToastsEnabled = false

private fun debugToast(context: Context, message: String, length: Int = Toast.LENGTH_SHORT) {
    if (!debugToastsEnabled) return
    Toast.makeText(context, message, length).show()
}

private class VolumeButtonDispatcher(
    private val context: Context,
    private val handler: Handler = Handler(Looper.getMainLooper())
) {
    private var lastVolumeDownTs: Long = 0L
    private var lastVolumeUpTs: Long = 0L
    private var pendingVolumeDown: Runnable? = null
    private var pendingVolumeUp: Runnable? = null

    fun onVolumeDown(single: (() -> Unit)?, double: (() -> Unit)?) {
        val now = System.currentTimeMillis()
        val delta = now - lastVolumeDownTs
        // Проверяем, это второе быстрое нажатие?
        if (delta in 0..VOLUME_DOUBLE_TAP_WINDOW_MS) {
            // Отменяем отложенное одиночное нажатие (если есть)
            pendingVolumeDown?.let {
                handler.removeCallbacks(it)
                println("### VolumeDown: cancelled pending single tap")
            }
            pendingVolumeDown = null
            lastVolumeDownTs = 0L

            // Вызываем обработчик двойного нажатия
            println("### VolumeDown: DOUBLE tap detected!")
            handler.post {
                debugToast(context, "VOL DOWN DOUBLE")
            }
            double?.invoke()
            return
        }
        // Первое нажатие - создаём отложенное одиночное нажатие
        lastVolumeDownTs = now
        val runnable = Runnable {
            pendingVolumeDown = null
            println("### VolumeDown: executing SINGLE tap (delayed)")
            debugToast(context, "VOL DOWN SINGLE")
            single?.invoke()
        }
        pendingVolumeDown = runnable
        handler.postDelayed(runnable, VOLUME_DOUBLE_TAP_WINDOW_MS)
        println("### VolumeDown: scheduled single tap (wait ${VOLUME_DOUBLE_TAP_WINDOW_MS}ms)")
    }

    fun onVolumeUp(single: (() -> Unit)?, double: (() -> Unit)?) {
        val now = System.currentTimeMillis()
        val delta = now - lastVolumeUpTs

        // Проверяем, это второе быстрое нажатие?
        if (delta in 0..VOLUME_DOUBLE_TAP_WINDOW_MS) {
            // Отменяем отложенное одиночное нажатие (если есть)
            pendingVolumeUp?.let {
                handler.removeCallbacks(it)
                println("### VolumeUp: cancelled pending single tap")
            }
            pendingVolumeUp = null
            lastVolumeUpTs = 0L

            // Вызываем обработчик двойного нажатия
            println("### VolumeUp: DOUBLE tap detected!")
            handler.post {
                debugToast(context, "VOL UP DOUBLE")
            }

            double?.invoke()
            return
        }

        // Первое нажатие - создаём отложенное одиночное нажатие
        lastVolumeUpTs = now
        val runnable = Runnable {
            pendingVolumeUp = null
            println("### VolumeUp: executing SINGLE tap (delayed)")
            debugToast(context, "VOL UP SINGLE")
            single?.invoke()
        }
        pendingVolumeUp = runnable
        handler.postDelayed(runnable, VOLUME_DOUBLE_TAP_WINDOW_MS)
        println("### VolumeUp: scheduled single tap (wait ${VOLUME_DOUBLE_TAP_WINDOW_MS}ms)")
    }
}

class MainActivity : ComponentActivity() {
    companion object {
        var onVolDownSingle: (() -> Unit)? = null
        var onVolDownDouble: (() -> Unit)? = null
        var onVolUpSingle: (() -> Unit)? = null
        var onVolUpDouble: (() -> Unit)? = null
        var activeWebViewProvider: (() -> WebView?)? = null
    }

    private lateinit var volumeButtonDispatcher: VolumeButtonDispatcher

    override fun onKeyDown(keyCode: Int, event: KeyEvent?): Boolean {
        val repeat = event?.repeatCount ?: 0

        // глушим авто-повтор ТОЛЬКО для кнопок громкости
        if (repeat > 0 && (keyCode == KeyEvent.KEYCODE_VOLUME_DOWN || keyCode == KeyEvent.KEYCODE_VOLUME_UP)) {
            return true
        }

        when (keyCode) {
            KeyEvent.KEYCODE_VOLUME_DOWN -> {
                volumeButtonDispatcher.onVolumeDown(onVolDownSingle, onVolDownDouble)
                return true
            }
            KeyEvent.KEYCODE_VOLUME_UP -> {
                volumeButtonDispatcher.onVolumeUp(onVolUpSingle, onVolUpDouble)
                return true
            }
        }
        return super.onKeyDown(keyCode, event)
    }
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // init volume dispatcher with context (for Toast)
        volumeButtonDispatcher = VolumeButtonDispatcher(this)

        // экран не гаснет — поведение киоска
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        enableEdgeToEdge()

        // блокируем "Назад"
        onBackPressedDispatcher.addCallback(
            this,
            object : OnBackPressedCallback(true) {
                override fun handleOnBackPressed() {
                    val activeWebView = MainActivity.activeWebViewProvider?.invoke()
                    if (activeWebView != null) {
                        activeWebView.evaluateJavascript(
                            """
                            (function() {
                                var modal = document.querySelector('.modal.show');
                                if (!modal) return false;

                                if (window.bootstrap && window.bootstrap.Modal) {
                                    var instance = window.bootstrap.Modal.getInstance(modal) || new window.bootstrap.Modal(modal);
                                    instance.hide();
                                } else {
                                    modal.classList.remove('show');
                                    modal.style.display = 'none';
                                    document.body.classList.remove('modal-open');
                                    var backdrop = document.querySelector('.modal-backdrop');
                                    if (backdrop) backdrop.remove();
                                }

                                return true;
                            })();
                            """.trimIndent()
                        ) { modalWasClosed ->
                            if (modalWasClosed != "true" && activeWebView.canGoBack()) {
                                activeWebView.goBack()
                            }
                        }
                        return
                    }
                    // в киоск-режиме остаемся в приложении
                }
            }
        )

        setContent {
            OcrScannerTestTheme {
                AppRoot()
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AppRoot() {
    val context = LocalContext.current
    val repo = remember { DeviceConfigRepository(context) }
    val scope = rememberCoroutineScope()

    var config by remember { mutableStateOf(repo.load()) }
    LaunchedEffect(config.debugToasts) {
        debugToastsEnabled = config.debugToasts
    }

    var showSettings by remember { mutableStateOf(!config.enrolled || config.serverUrl.isBlank()) }
    var showQrScan by remember { mutableStateOf(false) }
    var showWebView by remember { mutableStateOf(false) }
    // clear WebView cache/storage only on app start
    var shouldClearWebViewData by remember { mutableStateOf(true) }

    LaunchedEffect(Unit) {
        if (shouldClearWebViewData) {
            clearWebViewDataOnStart(context)
            shouldClearWebViewData = false
        }
    }


    // новый экран для OCR
    var showOcr by remember { mutableStateOf(false) }

    // ссылка на WebView
    var webViewRef by remember { mutableStateOf<WebView?>(null) }
    SideEffect {
        MainActivity.activeWebViewProvider = { webViewRef }
    }
    DisposableEffect(Unit) {
        onDispose {
            MainActivity.activeWebViewProvider = null
        }
    }
    var ocrTemplates by remember { mutableStateOf<OcrTemplates?>(null) }
    var lastQr by remember { mutableStateOf<String?>(null) }
    var loginError by remember { mutableStateOf<String?>(null) }
    var isLoggingIn by remember { mutableStateOf(false) }
    var destConfig by remember { mutableStateOf<List<DestCountryCfg>>(emptyList()) }

    var nameDict by remember { mutableStateOf<NameDict?>(null) }

    // session_id из сервера (по факту сейчас только храним для информации)
    var sessionId by remember { mutableStateOf<String?>(null) }

    var menuExpanded by remember { mutableStateOf(false) }
    var taskConfigJson by remember { mutableStateOf<String?>(null) }
    var taskConfig by remember { mutableStateOf<ScanTaskConfig?>(null) }



    // Шаги потока складского сканирования

    var warehouseScanStep by remember { mutableStateOf(WarehouseScanStep.BARCODE) }
    var currentFlowStep by remember { mutableStateOf<String?>(null) }
    var warehouseMoveScannerCellId by remember { mutableStateOf<String?>(null) }
    var warehouseMoveScannerCellCode by remember { mutableStateOf<String?>(null) }
    var warehouseMoveBatchCellId by remember { mutableStateOf<String?>(null) }
    var warehouseMoveBatchCellCode by remember { mutableStateOf<String?>(null) }

    // Оверлей штрих-кодов
    var showBarcodeScan by remember { mutableStateOf(false) }

    // колбэк, который будет вызываться при VOL_DOWN, когда открыт OCR
    var ocrHardwareTrigger by remember { mutableStateOf<(() -> Unit)?>(null) }
    var barcodeHardwareTrigger by remember { mutableStateOf<(() -> Unit)?>(null) }
    val buttonMappings = taskConfig?.buttons ?: emptyMap()
    val hasButtonMappings = buttonMappings.isNotEmpty()
    val taskIdNorm = taskConfig?.taskId?.trim()?.lowercase()
    val isWarehouseMove = taskConfig?.taskId == "warehouse_move"
    val isWarehouseIn = taskConfig?.taskId == "warehouse_in"
    val hasFlow = taskConfig?.flow != null

    // Check if any context has a flow
    val hasContextFlow = remember(taskConfig) {
        taskConfig?.contexts?.values?.any { it.flow != null } == true
    }
    val hasContexts = taskConfig?.contexts?.isNotEmpty() == true
    fun fieldSelector(action: ScanAction?): String? {
        val fieldId = action?.fieldId?.trim()?.takeIf { it.isNotEmpty() }
        val fieldName = action?.fieldName?.trim()?.takeIf { it.isNotEmpty() }
        return when {
            fieldId != null -> "#$fieldId"
            fieldName != null -> "[name=\"${escapeJsSelector(fieldName)}\"]"
            else -> null
        }
    }


    fun describeScanAction(action: ScanAction?): String {
        if (action == null) return "null"
        val base = action.action ?: "unknown"
        return when (base) {
            "fill_field" -> "fill_field ids=${action.fieldIds ?: action.fieldId ?: "?"}"
            "api_check" -> "api_check ${action.endpoint ?: "?"}"
            "web_callback" -> "web_callback ${action.callback ?: "?"}"
            else -> base
        }
    }

    fun openDefaultScanner() {
        when (taskConfig?.defaultMode?.lowercase()) {
            "ocr" -> {
                showOcr = true
            }
            "barcode", "qr" -> {
                showBarcodeScan = true
            }
            else -> {
                showBarcodeScan = true
            }
        }
    }

    fun triggerScanAction() {
        when {
            showBarcodeScan && barcodeHardwareTrigger != null -> barcodeHardwareTrigger?.invoke()
            showOcr && ocrHardwareTrigger != null -> ocrHardwareTrigger?.invoke()
            else -> openDefaultScanner()
        }
    }


    fun warehouseStepForFlow(step: String): WarehouseScanStep? = when (step) {
        "barcode" -> WarehouseScanStep.BARCODE
        "ocr" -> WarehouseScanStep.OCR
        "measure" -> WarehouseScanStep.MEASURE
        "submit" -> WarehouseScanStep.SUBMIT
        else -> null
    }

    fun setFlowStep(step: String) {
        currentFlowStep = step
        if (isWarehouseIn) {
            warehouseStepForFlow(step)?.let { warehouseScanStep = it }
        }
    }

    fun executeFlowOps(ops: List<FlowOp>) {
        if (ops.isEmpty()) return
        ops.forEach { op ->
            when (op) {
                is FlowOp.OpenScanner -> {
                    when (op.mode.lowercase()) {
                        "barcode" -> showBarcodeScan = true
                        "qr" -> showBarcodeScan = true
                        "ocr" -> showOcr = true
                    }
                }
                is FlowOp.Web -> {
                    webViewRef?.let { web ->
                        // ИСПРАВЛЕННЫЙ ВЫЗОВ:
                        val handled = handleNativeWebOp(
                            webView = web,
                            opName = op.name,
                            contextConfig = null, // Тут контекста нет
                            scope = scope,
                            config = config,
                            taskConfig = taskConfig,
                            scannerCellId = warehouseMoveScannerCellId,
                            batchCellId = warehouseMoveBatchCellId
                        )
                        if (handled) return@let

                        val escapedFunctionName = escapeJsString(op.name)
                        executeWebFunctionWithRetry(web, escapedFunctionName, op.name, maxRetries = 3)
                    }
                }
                is FlowOp.SetStep -> setFlowStep(op.to)
                FlowOp.Noop -> Unit
                is FlowOp.WebIf -> {
                    when (op.cond) {
                        "stand_selected" -> {
                            val web = webViewRef ?: return@forEach
                            withStandDeviceSelected(web) { selected ->
                                executeFlowOps(if (selected) op.thenOps else op.elseOps)
                            }
                        }
                        else -> executeFlowOps(op.elseOps)
                    }
                }
            }
        }
    }



    fun dispatchFlowAction(eventName: String) {
        val flow = taskConfig?.flow ?: return
        val action = taskConfig?.buttons?.get(eventName) ?: return
        val stepId = currentFlowStep ?: flow.start.also { setFlowStep(it) }
        val step = flow.steps[stepId] ?: return
        val ops = step.onAction[action] ?: return
        executeFlowOps(ops)
    }



    fun clearWarehouseMoveState(contextKey: String, contextConfig: ScanContextConfig) {
        val field = fieldSelector(contextConfig.barcode)
        val selectId = contextConfig.qr?.applyToSelectId
        webViewRef?.let { web ->
            field?.let { setInputValueBySelector(web, it, "") }
            selectId?.let { setSelectValueById(web, it, "") }
        }
        if (contextKey == "batch" || selectId != null) {
            warehouseMoveBatchCellId = null
            warehouseMoveBatchCellCode = null
        } else {
            warehouseMoveScannerCellId = null
            warehouseMoveScannerCellCode = null
        }
    }

    fun handleWarehouseMoveConfirm(contextKey: String, contextConfig: ScanContextConfig) {
        val moveEndpoint = taskConfig?.api?.get("move_apply") ?: return
        val trackingField = fieldSelector(contextConfig.barcode)
        if (trackingField.isNullOrBlank()) return
        val isBatchContext = contextKey == "batch" || contextConfig.qr?.applyToSelectId != null
        val cellId = if (isBatchContext) warehouseMoveBatchCellId else warehouseMoveScannerCellId
        if (cellId.isNullOrBlank()) return

        webViewRef?.let { web ->
            getInputValueBySelector(web, trackingField) { tracking ->
                val cleanTracking = tracking?.trim()?.takeIf { it.isNotEmpty() } ?: return@getInputValueBySelector
                scope.launch {
                    val result = applyWarehouseMoveWithSession(
                        cfg = config,
                        endpoint = moveEndpoint,
                        tracking = cleanTracking,
                        cellId = cellId,
                        mode = contextKey
                    )
                    if (result.ok) {
                        webViewRef?.let { view ->
                            setInputValueBySelector(view, trackingField, "")
                        }
                    }
                }
            }
        }
    }
    fun resolveActiveWarehouseContext(onResolved: (String, ScanContextConfig) -> Unit) {
        val cfg = taskConfig ?: return
        val webView = webViewRef ?: return

        val contexts = cfg.contexts

        // fallback: если contexts нет — используем корневые barcode/qr как единственный контекст
        if (contexts.isEmpty()) {
            val fallbackCtx = ScanContextConfig(
                activeTabSelector = null,
                barcode = cfg.barcodeAction,
                qr = cfg.qrAction
            )
            onResolved("default", fallbackCtx)
            return
        }

            // NEW: если страница/JS уже явно указала active_context — доверяем ему
            cfg.activeContext?.let { key ->
                val ctx = contexts[key]
                if (ctx != null) {
                    Handler(Looper.getMainLooper()).post {
                        debugToast(context, "CTX RESOLVE: active_context=$key")
                    }
                    onResolved(key, ctx)
                    return
                } else {
                    println("### resolveActiveWarehouseContext: active_context='$key' not found in contexts, fallback to selector")
                }
            }

        resolveActiveContextId(webView, contexts) { activeKey ->
            val resolvedKey = activeKey ?: contexts.keys.firstOrNull()
            val resolvedContext = resolvedKey?.let { contexts[it] }
            if (resolvedKey != null && resolvedContext != null) {
                Handler(Looper.getMainLooper()).post {
                    debugToast(context, "CTX RESOLVE: selector=$resolvedKey")
                }
                onResolved(resolvedKey, resolvedContext)
            }
        }
    }
    fun warehouseInDownSingle() {
        when (warehouseScanStep) {
            WarehouseScanStep.BARCODE -> {
                webViewRef?.let { web -> clearTrackingAndTuidInWebView(web) }
                showBarcodeScan = true
            }
            WarehouseScanStep.OCR -> {

                showOcr = true
            }
            WarehouseScanStep.MEASURE -> {
                webViewRef?.let { web ->
                    withStandDeviceSelected(web) { selected ->
                        if (selected) {
                            requestStandMeasurementInWebView(web)
                            warehouseScanStep = WarehouseScanStep.SUBMIT
                        } else {
                            prepareFormForNextScanInWebView(web)
                            warehouseScanStep = WarehouseScanStep.BARCODE
                        }
                    }
                }
            }
            WarehouseScanStep.SUBMIT -> {
                webViewRef?.let { web -> prepareFormForNextScanInWebView(web) }
                warehouseScanStep = WarehouseScanStep.BARCODE
            }
        }
    }

    fun warehouseInConfirm() {
        // confirm имеет смысл только на SUBMIT (добавление/фиксирование)
        if (warehouseScanStep == WarehouseScanStep.SUBMIT) {
            webViewRef?.let { web -> prepareFormForNextScanInWebView(web) }
            warehouseScanStep = WarehouseScanStep.BARCODE
        }
    }

    fun warehouseInUpSingle() {
        when (warehouseScanStep) {
            WarehouseScanStep.BARCODE -> {
                webViewRef?.let { web -> clearTrackingAndTuidInWebView(web) }
            }
            WarehouseScanStep.OCR -> {
                webViewRef?.let { web -> clearParcelFormExceptTrack(web) }
                warehouseScanStep = WarehouseScanStep.OCR
            }
            WarehouseScanStep.MEASURE -> {
                webViewRef?.let { web ->
                    withStandDeviceSelected(web) { selected ->
                        if (selected) {
                            clearMeasurementsInWebView(web)
                        } else {
                            clearParcelFormExceptTrack(web)
                            warehouseScanStep = WarehouseScanStep.OCR
                        }
                    }
                }
            }
            WarehouseScanStep.SUBMIT -> {
                webViewRef?.let { web ->
                    withStandDeviceSelected(web) { selected ->
                        if (selected) {
                            clearMeasurementsInWebView(web)
                            warehouseScanStep = WarehouseScanStep.MEASURE
                        } else {
                            clearParcelFormExceptTrack(web)
                            warehouseScanStep = WarehouseScanStep.OCR
                        }
                    }
                }
            }
        }
    }

    fun warehouseInResetAll() {
        webViewRef?.let { web -> clearParcelFormInWebView(web) }
        warehouseScanStep = WarehouseScanStep.BARCODE
        showOcr = false
        showBarcodeScan = false
    }
    fun dispatchButtonAction(action: String?) {
        when (action) {

            "scan" -> {
                // если уже открыт overlay — scan должен триггерить камеру
                if (showBarcodeScan || showOcr) {
                    triggerScanAction()
                    return
                }
                // если warehouse_in и мы на WebView — рулит step machine
                if (isWarehouseIn && showWebView) {
                    warehouseInDownSingle()
                    return
                }
                triggerScanAction()
            }

            "confirm" -> {
                when {
                    isWarehouseMove -> {
                        resolveActiveWarehouseContext { key, ctx ->
                            // ИСПРАВЛЕННЫЙ ВЫЗОВ:
                            handleWarehouseMoveConfirm(
                                webView = webViewRef,
                                contextKey = key,
                                contextConfig = ctx,
                                scope = scope,
                                config = config,
                                taskConfig = taskConfig,
                                scannerCellId = warehouseMoveScannerCellId,
                                batchCellId = warehouseMoveBatchCellId
                            )
                        }
                    }
                    isWarehouseIn -> {
                        warehouseInConfirm()
                    }
                }
            }

            "clear" -> {
                when {
                    isWarehouseMove -> {
                        resolveActiveWarehouseContext { key, ctx ->
                            clearWarehouseMoveState(webViewRef, ctx)
                            // Эти строки оставьте как были, они сбрасывают переменные Compose
                            warehouseMoveBatchCellId = null
                            warehouseMoveScannerCellId = null
                        }
                    }
                    isWarehouseIn -> {
                        warehouseInUpSingle()
                    }
                    else -> {
                        webViewRef?.let { web -> clearParcelFormInWebView(web) }
                    }
                }
            }

            "reset" -> {
                // отдельное действие для double-up в твоей логике
                when {
                    isWarehouseIn -> warehouseInResetAll()
                    else -> webViewRef?.let { web -> clearParcelFormInWebView(web) }
                }
            }

            "back" -> {
                val web = webViewRef
                if (web != null && web.canGoBack()) {
                    web.goBack()
                } else {
                    showWebView = false
                }
            }

            "toggle_mode", "noop", null -> Unit
        }
    }

    fun executeFlowActionsInContext(
        actions: List<FlowOp>,
        contextConfig: ScanContextConfig?
    ) {
        val contextFlow = contextConfig?.flow
        val globalFlow = taskConfig?.flow

        for (op in actions) {
            when (op) {
                is FlowOp.OpenScanner -> {
                    when (op.mode) {
                        "barcode", "qr" -> showBarcodeScan = true
                        "ocr" -> showOcr = true
                    }
                }
                is FlowOp.SetStep -> {
                    // Используем context flow если есть, иначе глобальный
                    val flow = contextFlow ?: globalFlow
                    if (flow != null && flow.steps.containsKey(op.to)) {
                        setFlowStep(op.to)
                    }
                }
                is FlowOp.Web -> {
                    webViewRef?.let { web ->
                        // ИСПРАВЛЕННЫЙ ВЫЗОВ:
                        val handled = handleNativeWebOp(
                            webView = web,
                            opName = op.name,
                            contextConfig = contextConfig, // Тут контекст есть
                            scope = scope,
                            config = config,
                            taskConfig = taskConfig,
                            scannerCellId = warehouseMoveScannerCellId,
                            batchCellId = warehouseMoveBatchCellId
                        )
                        if (handled) return@let

                        val escapedFunctionName = escapeJsString(op.name)
                        executeWebFunctionWithRetry(web, escapedFunctionName, op.name, maxRetries = 3, showToast = true)
                    }
                }
                is FlowOp.Noop -> { /* do nothing */ }
                is FlowOp.WebIf -> {
                    when (op.cond) {
                        "stand_selected" -> {
                            val web = webViewRef ?: return
                            withStandDeviceSelected(web) { selected ->
                                executeFlowActionsInContext(if (selected) op.thenOps else op.elseOps, contextConfig)
                            }
                        }
                        else -> executeFlowActionsInContext(op.elseOps, contextConfig)
                    }
                }
            }
        }
    }

    fun handleNativeWebOp(webView: WebView, opName: String, contextConfig: ScanContextConfig?): Boolean {
        return when (opName) {
            "clear_search" -> {
                val field = fieldSelector(contextConfig?.barcode) ?: "#warehouse-move-search"
                setInputValueBySelector(webView, field, "")
                true
            }
            "reset_form" -> {
                contextConfig?.let { clearWarehouseMoveState("", it) }
                clearWarehouseMoveResultsInWebView(webView)
                true
            }
            "apply_move" -> {
                contextConfig?.let { handleWarehouseMoveConfirm("", it) }
                true
            }
            "openMoveModal" -> {
                openWarehouseMoveModalInWebView(webView)
                true
            }
            "triggerSaveButton" -> {
                triggerWarehouseMoveSaveInWebView(webView)
                true
            }
            "confirmBatchMove" -> {
                confirmWarehouseMoveBatchInWebView(webView)
                true
            }
            "confirmBoxMove" -> {
                clickElementBySelector(webView, "#warehouse-move-box .js-core-link[data-core-action=\"warehouse_move_box_assign\"]")
                true
            }
            "clearToolsStorageMoveSearch" -> {
                setInputValueBySelector(webView, "#tools-storage-move-search", "")
                true
            }
            "resetToolsUserSelection" -> {
                setSelectValueById(webView, "toolAssignedUser", "")
                true
            }
            "resetToolsCellSelection" -> {
                setSelectValueById(webView, "toolStorageCell", "")
                true
            }
            "triggerToolsManagementSave" -> {
                triggerToolsManagementSaveInWebView(webView)
                true
            }
            "clear_tracking" -> {
                clearTrackingAndTuidInWebView(webView)
                true
            }
            "clear_except_track" -> {
                clearParcelFormExceptTrack(webView)
                true
            }
            "clear_all" -> {
                clearAllInWebView(webView)
                true
            }
            "clear_measurements" -> {
                clearMeasurementsInWebView(webView)
                true
            }
            "measure_request" -> {
                requestStandMeasurementInWebView(webView)
                true
            }
            "add_new_item" -> {
                prepareFormForNextScanInWebView(webView)
                true
            }
            "upload_item_stock_photo_label" -> {
                captureWarehouseItemStockPhotoInWebView(webView, "label")
                true
            }
            "upload_item_stock_photo_box" -> {
                captureWarehouseItemStockPhotoInWebView(webView, "box")
                true
            }
            else -> false
        }
    }
    fun dispatchContextFlowAction(eventName: String) {
        if (!hasContextFlow) return

        // DEBUG: confirm that volume event reaches context flow dispatcher
        Handler(Looper.getMainLooper()).post {
            debugToast(context, "CTX FLOW EVENT: $eventName")
        }

        resolveActiveWarehouseContext { contextKey: String, contextConfig: ScanContextConfig ->
            val contextFlow: FlowConfig? = contextConfig.flow
            if (contextFlow != null) {
                val action = taskConfig?.buttons?.get(eventName) ?: return@resolveActiveWarehouseContext
                val flowStartStep: String = contextFlow.start
                // If currentFlowStep is not part of THIS context flow, reset to context start
                val stepId = if (currentFlowStep != null && contextFlow.steps.containsKey(currentFlowStep!!)) {
                    currentFlowStep!!
                } else {
                    flowStartStep.also { setFlowStep(it) }
                }

                val step: FlowStep? = contextFlow.steps[stepId]
                val ops: List<FlowOp> = step?.onAction?.get(action) ?: emptyList()


                // DEBUG: show what we resolved
                Handler(Looper.getMainLooper()).post {
                    debugToast(
                        context,
                        "CTX=$contextKey step=$stepId action=$action ops=${ops.size}"
                    )
                }

                // DEBUG: show first op and webView presence
                val webOk = (webViewRef != null)
                val firstOp = ops.firstOrNull()?.toString() ?: "null"
                Handler(Looper.getMainLooper()).post {
                    debugToast(context, "webView=$webOk op0=$firstOp", Toast.LENGTH_LONG)
                }

                if (ops.isNotEmpty()) {
                    executeFlowActionsInContext(ops, contextConfig)
                } else {
                    // Fallback на старую логику если нет действий
                    dispatchButtonAction(action)
                }
            } else {
                // Если нет flow в контексте, используем старую логику
                val action = taskConfig?.buttons?.get(eventName)
                dispatchButtonAction(action)
            }
        }
    }

    LaunchedEffect(
        showWebView,
        showOcr,
        showBarcodeScan,
        ocrHardwareTrigger,
        barcodeHardwareTrigger,
        webViewRef,
        taskConfig
    ) {
        when {
            // IMPORTANT: Prefer context flow over legacy buttonMappings,
            // but keep hardware triggers for scanner overlays (so VolDownSingle can "take scan").
            showWebView && hasContextFlow -> {
                MainActivity.onVolDownSingle = {
                    when {
                        showBarcodeScan && barcodeHardwareTrigger != null -> barcodeHardwareTrigger?.invoke()
                        showOcr && ocrHardwareTrigger != null -> ocrHardwareTrigger?.invoke()
                        else -> dispatchContextFlowAction("vol_down_single")
                    }
                }
                MainActivity.onVolDownDouble = { dispatchContextFlowAction("vol_down_double") }
                MainActivity.onVolUpSingle = { dispatchContextFlowAction("vol_up_single") }
                MainActivity.onVolUpDouble = { dispatchContextFlowAction("vol_up_double") }
            }

            hasButtonMappings && (showWebView || showBarcodeScan || showOcr) && !hasFlow -> {
                Handler(Looper.getMainLooper()).post {
                    debugToast(context, "MODE: buttonMappings(no global flow)")
                }

                MainActivity.onVolDownSingle = { dispatchButtonAction(buttonMappings["vol_down_single"]) }
                MainActivity.onVolDownDouble = { dispatchButtonAction(buttonMappings["vol_down_double"]) }
                MainActivity.onVolUpSingle = { dispatchButtonAction(buttonMappings["vol_up_single"]) }
                MainActivity.onVolUpDouble = { dispatchButtonAction(buttonMappings["vol_up_double"]) }
            }

            showBarcodeScan && barcodeHardwareTrigger != null -> {
                Handler(Looper.getMainLooper()).post {
                    debugToast(context, "MODE: barcode overlay")
                }

                MainActivity.onVolDownSingle = { barcodeHardwareTrigger?.invoke() }
                // IMPORTANT:
                // When scanner overlay is shown, keep CONFIRM/CLEAR/RESET working via context flow.
                // VolDownSingle stays as "scan trigger", but VolDownDouble should execute flow "confirm" (save/open modal).
                    if (hasContextFlow) {
                        MainActivity.onVolDownDouble = { dispatchContextFlowAction("vol_down_double") }
                        MainActivity.onVolUpSingle = { dispatchContextFlowAction("vol_up_single") }
                        MainActivity.onVolUpDouble = { dispatchContextFlowAction("vol_up_double") }
                    } else {
                        MainActivity.onVolDownDouble = null
                        MainActivity.onVolUpSingle = null
                        MainActivity.onVolUpDouble = null
                   }
            }

            showOcr && ocrHardwareTrigger != null -> {
                Handler(Looper.getMainLooper()).post {
                    debugToast(context, "MODE: OCR overlay")
                }

                MainActivity.onVolDownSingle = { ocrHardwareTrigger?.invoke() }
                // Same logic for OCR overlay (if used in context flows)
                   if (hasContextFlow) {
                       MainActivity.onVolDownDouble = { dispatchContextFlowAction("vol_down_double") }
                       MainActivity.onVolUpSingle = { dispatchContextFlowAction("vol_up_single") }
                       MainActivity.onVolUpDouble = { dispatchContextFlowAction("vol_up_double") }
                   } else {
                       MainActivity.onVolDownDouble = null
                       MainActivity.onVolUpSingle = null
                       MainActivity.onVolUpDouble = null
                   }
            }




            showWebView -> {
                Handler(Looper.getMainLooper()).post {
                    debugToast(context, "MODE: webview")
                }

                if (hasFlow) {
                    MainActivity.onVolDownSingle = { dispatchFlowAction("vol_down_single") }
                    MainActivity.onVolDownDouble = { dispatchFlowAction("vol_down_double") }
                    MainActivity.onVolUpSingle = { dispatchFlowAction("vol_up_single") }
                    MainActivity.onVolUpDouble = { dispatchFlowAction("vol_up_double") }
                } else if (hasContextFlow) {
                    Handler(Looper.getMainLooper()).post {
                        debugToast(context, "MODE: webview + contextFlow")
                    }
                    // Используем context flow
                    MainActivity.onVolDownSingle = { dispatchContextFlowAction("vol_down_single") }
                    MainActivity.onVolDownDouble = { dispatchContextFlowAction("vol_down_double") }
                    MainActivity.onVolUpSingle = { dispatchContextFlowAction("vol_up_single") }
                    MainActivity.onVolUpDouble = { dispatchContextFlowAction("vol_up_double") }
                } else {
                    Handler(Looper.getMainLooper()).post {
                        debugToast(context, "MODE: webview (legacy)")
                    }

                    MainActivity.onVolDownSingle = {
                        if (isWarehouseIn) {
                            when (warehouseScanStep) {
                                WarehouseScanStep.BARCODE -> {
                                    webViewRef?.let { web -> clearTrackingAndTuidInWebView(web) }
                                    showBarcodeScan = true
                                }
                                WarehouseScanStep.OCR -> {
                                    showOcr = true
                                }
                                WarehouseScanStep.MEASURE -> {
                                    webViewRef?.let { web ->
                                        withStandDeviceSelected(web) { selected ->
                                            if (selected) {
                                                requestStandMeasurementInWebView(web)
                                                warehouseScanStep = WarehouseScanStep.SUBMIT
                                            } else {
                                                prepareFormForNextScanInWebView(web)
                                                warehouseScanStep = WarehouseScanStep.BARCODE
                                            }
                                        }
                                    }
                                }
                                WarehouseScanStep.SUBMIT -> {
                                    webViewRef?.let { web -> prepareFormForNextScanInWebView(web) }
                                    warehouseScanStep = WarehouseScanStep.BARCODE
                                }
                            }
                        } else {
                            webViewRef?.let { web -> prepareFormForNextScanInWebView(web) }


                            showOcr = true

                            when (taskConfig?.defaultMode) {
                                "qr"      -> { showQrScan = true }
                                "barcode" -> { /* showBarcodeScan = true */ }
                                "ocr"     -> { showOcr = true }
                                else      -> { showOcr = true }
                            }
                        }
                    }
                    MainActivity.onVolDownDouble = {
                        if (isWarehouseIn) {
                            warehouseInConfirm()
                        }
                    }
                    MainActivity.onVolUpDouble = {
                        if (isWarehouseIn) {
                            webViewRef?.let { web -> clearParcelFormInWebView(web) }
                            warehouseScanStep = WarehouseScanStep.BARCODE
                            showOcr = false
                            showBarcodeScan = false
                        } else {
                            webViewRef?.let { web -> clearActiveElementValue(web) }
                        }
                    }
                    MainActivity.onVolUpSingle = {
                        if (isWarehouseIn) {

                            when (warehouseScanStep) {
                                WarehouseScanStep.BARCODE -> {
                                    webViewRef?.let { web -> clearTrackingAndTuidInWebView(web) }
                                }
                                WarehouseScanStep.OCR -> {
                                    webViewRef?.let { web -> clearParcelFormExceptTrack(web) }
                                    warehouseScanStep = WarehouseScanStep.OCR
                                }
                                WarehouseScanStep.MEASURE -> {
                                    webViewRef?.let { web ->
                                        withStandDeviceSelected(web) { selected ->
                                            if (selected) {
                                                clearMeasurementsInWebView(web)
                                            } else {
                                                clearParcelFormExceptTrack(web)
                                                warehouseScanStep = WarehouseScanStep.OCR
                                            }
                                        }
                                    }
                                }
                                WarehouseScanStep.SUBMIT -> {
                                    webViewRef?.let { web ->
                                        withStandDeviceSelected(web) { selected ->
                                            if (selected) {
                                                clearMeasurementsInWebView(web)
                                                warehouseScanStep = WarehouseScanStep.MEASURE
                                            } else {
                                                clearParcelFormExceptTrack(web)
                                                warehouseScanStep = WarehouseScanStep.OCR
                                            }
                                        }
                                    }
                                }
                            }
                        } else {
                            webViewRef?.let { web -> clearParcelFormInWebView(web) }
                        }
                    }
                }
            }

            else -> {
                Handler(Looper.getMainLooper()).post {
                    debugToast(context, "MODE: handlers=null")
                }

                MainActivity.onVolDownSingle = null
                MainActivity.onVolDownDouble = null
                MainActivity.onVolUpSingle = null
                MainActivity.onVolUpDouble = null
            }
        }
    }
    Scaffold(
        topBar = {
            // как и было: прячем верхнюю панель, когда открыт WebView
            if (!showWebView) {
                TopAppBar(
                    title = {
                        Text(
                            when {
                                showSettings -> "Настройки устройства"
                                showQrScan   -> "Сканирование QR"
                                else         -> "Сканер / терминал"
                            }
                        )
                    },
                    navigationIcon = {
                        Box {
                            IconButton(onClick = { menuExpanded = true }) {
                                Icon(
                                    imageVector = Icons.Default.Menu,
                                    contentDescription = "Меню"
                                )
                            }

                            DropdownMenu(
                                expanded = menuExpanded,
                                onDismissRequest = { menuExpanded = false }
                            ) {
                                DropdownMenuItem(
                                    text = { Text("Настройки") },
                                    onClick = {
                                        menuExpanded = false
                                        showSettings = true
                                        showQrScan = false
                                        showWebView = false
                                    }
                                )
                            }
                        }
                    }
                )
            }
        }
    ) { innerPadding ->
        Box(
            modifier = Modifier
                .padding(innerPadding)
                .fillMaxSize()
        ) {

            // ОСНОВНОЙ КОНТЕНТ (как раньше, но БЕЗ showOcr)
            when {
                showSettings -> {
                    SettingsScreen(
                        modifier = Modifier.fillMaxSize(),
                        config = config,
                        onConfigChanged = { newCfg ->
                            config = newCfg
                            repo.save(newCfg)
                        },
                        onEnrollSuccess = { token ->
                            val updated = config.copy(
                                deviceToken = token,
                                enrolled = true
                            )
                            config = updated
                            repo.save(updated)
                            showSettings = false
                        },
                        onUnenroll = {
                            repo.clearEnroll()
                            config = repo.load()

                            val cm = CookieManager.getInstance()
                            cm.removeAllCookies(null)
                            cm.flush()

                            sessionId = null
                            showWebView = false
                            lastQr = null
                            loginError = null

                            showSettings = true
                            showQrScan = false
                            showWebView = false
                        },
                        onClose = {
                            showSettings = false
                        }
                    )
                }

                showQrScan -> {
                    QrScanScreen(
                        modifier = Modifier.fillMaxSize(),
                        onCodeScanned = { raw ->
                            showQrScan = false
                            lastQr = raw
                            loginError = null
                            isLoggingIn = true

                            scope.launch {
                                val result = qrLoginOnServer(context, config, raw)
                                isLoggingIn = false

                                if (result.ok && !result.sessionId.isNullOrBlank()) {
                                    sessionId = result.sessionId

                                    val base = normalizeServerUrl(config.serverUrl)
                                    val cookieManager = CookieManager.getInstance()
                                    cookieManager.setAcceptCookie(true)
                                   // val cookieStr = "PHPSESSID=${result.sessionId}; Path=/; Secure"
                                    val isHttps = base.startsWith("https://", ignoreCase = true)

                                    val cookieStr = buildString {
                                        append("PHPSESSID=${result.sessionId}; Path=/; SameSite=Lax")
                                        if (isHttps) append("; Secure")
                                    }
                                    cookieManager.setCookie(base, cookieStr)
                                    cookieManager.flush()

                                    showWebView = true
                                } else {
                                    loginError = result.errorMessage ?: "Ошибка логина по QR"
                                }
                            }
                        },
                        onCancel = {
                            showQrScan = false
                        }
                    )
                }

                showWebView -> {
                    DeviceWebViewScreen(
                        modifier = Modifier.padding(innerPadding),
                        config = config,
                        shouldClearWebViewData = shouldClearWebViewData,
                        onWebViewDataCleared = { shouldClearWebViewData = false },
                        onWebViewReady = { webView -> webViewRef = webView },

                        onContextUpdated = { taskJson, tmplJson, destJson, dictJson ->

                            val parsedTask = taskJson?.let { parseScanTaskConfig(it) }
                            if (parsedTask != null) {
                                taskConfigJson = taskJson
                                val prevTaskId = taskConfig?.taskId
                                val prevJson = taskConfigJson

                                taskConfig = parsedTask
                                taskConfigJson = taskJson

                                val flow = parsedTask.flow
                                if (flow != null) {
                                    val stepValid = currentFlowStep?.let { flow.steps.containsKey(it) } ?: false
                                    val taskChanged = (prevTaskId == null) || (prevTaskId != parsedTask.taskId) || (prevJson != taskJson)

                                    if (!stepValid || taskChanged) {
                                        setFlowStep(flow.start) // setFlowStep() у тебя уже обновляет warehouseScanStep
                                    }
                                }
                            }
                            // ВАЖНО: если taskJson == null — ничего не делаем, оставляем старый taskConfig

                            tmplJson?.let { ocrTemplates = parseOcrTemplates(it) }
                            destJson?.let { destConfig = parseDestConfigJson(it) }

                            dictJson?.let {
                                val parsed = parseNameDictJson(it)
                                if (config.syncNameDict && parsed != null) {
                                    nameDict = parsed
                                }
                            }
                        },
                        onSessionEnded = {
                            val cm = CookieManager.getInstance()
                            cm.removeAllCookies(null)
                            cm.flush()

                            sessionId = null
                            showWebView = false
                            lastQr = null
                            loginError = "Сессия завершена"
                        },
                        onTemplatesLoaded = { tmpl ->
                            ocrTemplates = tmpl
                            // если хочешь проверить — можно временно повесить лог
                            // println("OCR templates loaded: $tmpl")
                        },
                        onNameDictLoaded = { dict ->
                            if (config.syncNameDict && dict != null) {
                                nameDict = dict
                            }
                        }
                    )
                }

                else -> {
                    LoginReadyScreen(
                        modifier = Modifier.fillMaxSize(),
                        config = config,
                        lastQr = lastQr,
                        isLoggingIn = isLoggingIn,
                        loginError = loginError,
                        onScanQr = {
                            showWebView = false
                            showQrScan = true
                        }
                    )
                }
            }


            // Оверлей BARCODE поверх WebView
            if (showBarcodeScan) {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background.copy(alpha = 0.98f)
                ) {
                    val isWarehouseIn = taskConfig?.taskId == "warehouse_in"
                    BarcodeScanScreen(
                        modifier = Modifier.fillMaxSize(),
                        config = config,
                        onResult = { result ->
                            showBarcodeScan = false
                            val resolvedResult = normalizeTrackingScanResult(result)
                            if (hasContexts) {
                                resolveActiveWarehouseContext { contextKey, contextConfig ->
                                    // НОВАЯ ЛОГИКА: проверяем, есть ли flow у контекста
                                    val contextFlow = contextConfig.flow
                                    val stepId = if (contextFlow != null) {
                                        val candidate = currentFlowStep
                                        if (candidate != null && contextFlow.steps.containsKey(candidate)) {
                                            candidate
                                        } else {
                                            contextFlow.start
                                        }                                    } else {
                                        null
                                    }
                                    val currentStep = stepId?.let { contextFlow?.steps?.get(it) }
                                    // Определяем действие: сначала из шага, потом из контекста (fallback)
                                    val action = if (resolvedResult.isQr) {
                                        currentStep?.qrAction
                                            ?: currentStep?.barcodeAction
                                            ?: contextConfig.qr
                                            ?: contextConfig.barcode
                                    } else {
                                        currentStep?.barcodeAction ?: contextConfig.barcode
                                    }
                                    if (action?.action == "web_callback") {
                                        Handler(Looper.getMainLooper()).post {
                                            debugToast(
                                                context,
                                                "SCAN web_callback ctx=$contextKey step=${stepId ?: "none"} fn=${action.callback ?: "?"}"
                                            )
                                        }
                                    } else {
                                        Handler(Looper.getMainLooper()).post {
                                            debugToast(
                                                context,
                                                "SCAN ctx=$contextKey step=${stepId ?: "none"} type=${if (resolvedResult.isQr) "qr" else "barcode"} action=${describeScanAction(action)}"
                                            )
                                        }
                                    }
                                    if (isWarehouseMove) {
                                        handleWarehouseMoveScanResult(
                                            config = config,
                                            scope = scope,
                                            webView = webViewRef,
                                            scanResult = resolvedResult,
                                            action = action,
                                            contextKey = contextKey,
                                            contextConfig = contextConfig,
                                            onScannerCellUpdate = { cellId, cellCode ->
                                                warehouseMoveScannerCellId = cellId
                                                warehouseMoveScannerCellCode = cellCode
                                            },
                                            onBatchCellUpdate = { cellId, cellCode ->
                                                warehouseMoveBatchCellId = cellId
                                                warehouseMoveBatchCellCode = cellCode
                                            }
                                        )


                                    } else {
                                        val cleanBarcode = sanitizeBarcodeInput(resolvedResult.rawValue)
                                        val scanValue = if (action?.action == "web_callback") {
                                            resolvedResult.rawValue
                                        } else {
                                            cleanBarcode
                                        }
                                        webViewRef?.let { web ->
                                            fillBarcodeUsingTemplate(
                                                web = web,
                                                rawBarcode = scanValue,
                                                action = action,
                                                config = config,
                                                scope = scope,
                                                isQr = resolvedResult.isQr
                                            )
                                        }
                                    }
                                    // Переходим к следующему шагу flow
                                    currentStep?.nextOnScan?.let { next ->
                                        setFlowStep(next)
                                    }
                                }
                            } else {
                                val cleanBarcode = sanitizeBarcodeInput(resolvedResult.rawValue)
                                val data = buildParcelFromBarcode(cleanBarcode, sanitizeInput = false)
                                webViewRef?.let { web ->
                                    val action = if (resolvedResult.isQr) {
                                        taskConfig?.qrAction ?: taskConfig?.barcodeAction

                                    } else {
                                        taskConfig?.barcodeAction
                                    }
                                    val scanValue = if (action?.action == "web_callback") {
                                        resolvedResult.rawValue                                    } else {
                                        cleanBarcode
                                    }
                                    fillBarcodeUsingTemplate(
                                        web = web,
                                        rawBarcode = scanValue,
                                        action = action,
                                        config = config,
                                        scope = scope,
                                        isQr = resolvedResult.isQr
                                    )
                                    if (!isWarehouseIn) {
                                        fillParcelFormInWebView(web, data, taskConfig)
                                    }
                                }
                                val flow = taskConfig?.flow
                                if (flow != null) {
                                    val stepId = currentFlowStep ?: flow.start
                                    flow.steps[stepId]?.nextOnScan?.let { next ->
                                        setFlowStep(next)
                                    }
                                } else if (isWarehouseIn) {
                                    warehouseScanStep = WarehouseScanStep.OCR
                                }
                            }
                        },
                        onCancel = {
                            showBarcodeScan = false
                        },
                        onBindHardwareTrigger = { action ->
                            barcodeHardwareTrigger = action
                        },
                        taskConfig = taskConfig,
                        scanMode = "barcode",
                        showCameraModeControls = config.cameraModeEnabled
                    )
                }
            }

            // ОВЕРЛЕЙ OCR ПОВЕРХ WebView (и любого экрана)
            if (showOcr) {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background.copy(alpha = 0.98f)
                ) {
                    val isWarehouseIn = taskConfig?.taskId == "warehouse_in"
                    OcrScanScreen(
                        modifier = Modifier.fillMaxSize(),
                        destConfig = destConfig,
                        config = config,
                        nameDict = nameDict,
                        taskConfig = taskConfig,
                        scanMode = "ocr",
                        onResult = { ocrData ->
                            showOcr = false
                            webViewRef?.let { web ->
                                fillParcelFormInWebView(
                                    webView = web,
                                    data = ocrData,
                                    config = taskConfig,
                                    includeCarrierFields = false
                                )
                            }

                            // НОВОЕ: поддержка context flow
                            if (isWarehouseMove) {
                                resolveActiveWarehouseContext { contextKey: String, contextConfig: ScanContextConfig ->
                                    val contextFlow: FlowConfig? = contextConfig.flow
                                    if (contextFlow != null) {
                                        val flowStartStep: String = contextFlow.start
                                        val stepId = currentFlowStep ?: flowStartStep
                                        contextFlow.steps[stepId]?.nextOnScan?.let { next ->
                                            setFlowStep(next)
                                        }
                                    }
                                }
                            } else {
                                // Глобальный flow для warehouse_in и других
                                val flow = taskConfig?.flow
                                if (flow != null) {
                                    val stepId = currentFlowStep ?: flow.start
                                    flow.steps[stepId]?.nextOnScan?.let { next ->
                                        setFlowStep(next)
                                    }
                                } else if (isWarehouseIn) {
                                    warehouseScanStep = WarehouseScanStep.MEASURE
                                }

                            }
                        },
                        onCancel = {
                            showOcr = false
                        },
                        onBindHardwareTrigger = { action ->
                            ocrHardwareTrigger = action
                        },
                        onBarcodeClick = {
                            showOcr = false
                            showBarcodeScan = true
                        },
                        onBpClick = {
                            webViewRef?.let { web ->
                                requestStandMeasurementInWebView(web)
                            }
                        },
                        showCameraModeControls = config.cameraModeEnabled
                    )
                }
            }

        }
    }
}

fun normalizeServerUrl(raw: String): String {
    var s = raw.trim()
    s = s.removeSuffix("/")
    s = s.removeSuffix("/api/device_enroll.php")
    return s
}
@Composable
fun SettingsScreen(
    modifier: Modifier = Modifier,
    config: DeviceConfig,
    onConfigChanged: (DeviceConfig) -> Unit,
    onEnrollSuccess: (String) -> Unit,
    onUnenroll: () -> Unit,
    onClose: () -> Unit
) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current

    var serverUrl by remember { mutableStateOf(config.serverUrl) }
    var deviceName by remember { mutableStateOf(config.deviceName) }
    var statusText by remember { mutableStateOf("") }
    var isBusy by remember { mutableStateOf(false) }
    var allowInsecure by remember { mutableStateOf(config.allowInsecureSsl) }
    var useRemoteOcr by remember { mutableStateOf(config.useRemoteOcr) }
    var liveScanEnabled by remember { mutableStateOf(config.liveScanEnabled) }
    var syncNameDict by remember { mutableStateOf(config.syncNameDict) }
    var debugToasts by remember { mutableStateOf(config.debugToasts) }
    var cameraModeEnabled by remember { mutableStateOf(config.cameraModeEnabled) }

    val scrollState = rememberScrollState()

    Column(
        modifier = modifier
            .fillMaxSize()
            .padding(16.dp)
            .verticalScroll(scrollState)
    ) {
        Text("Настройки устройства", style = MaterialTheme.typography.titleLarge)

        Spacer(Modifier.height(16.dp))

        OutlinedTextField(
            value = serverUrl,
            onValueChange = { newVal -> serverUrl = newVal },
            label = { Text("Server URL") },
            singleLine = true,
            modifier = Modifier.fillMaxWidth()
        )

        Spacer(Modifier.height(8.dp))

        OutlinedTextField(
            value = deviceName,
            onValueChange = { newVal -> deviceName = newVal },
            label = { Text("Имя устройства") },
            singleLine = true,
            modifier = Modifier.fillMaxWidth()
        )

        Spacer(Modifier.height(12.dp))

        // --- SSL ---
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.fillMaxWidth()
        ) {
            Checkbox(
                checked = allowInsecure,
                onCheckedChange = { allowInsecure = it }
            )
            Spacer(Modifier.width(8.dp))
            Column {
                Text(
                    "Игнорировать ошибки сертификата",
                    style = MaterialTheme.typography.bodyMedium
                )
                Text(
                    "(НЕБЕЗОПАСНО, только для теста)",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.error
                )
            }
        }

        Spacer(Modifier.height(8.dp))

        // --- удалённый OCR ---
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.fillMaxWidth()
        ) {
            Checkbox(
                checked = useRemoteOcr,
                onCheckedChange = { useRemoteOcr = it }
            )
            Spacer(Modifier.width(8.dp))
            Text(
                "Удалённый OCR-парсер",
                style = MaterialTheme.typography.bodyMedium
            )
        }

        Spacer(Modifier.height(8.dp))

        // --- live scan ---
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.fillMaxWidth()
        ) {
            Checkbox(
                checked = liveScanEnabled,
                onCheckedChange = { liveScanEnabled = it }
            )
            Spacer(Modifier.width(8.dp))
            Text(
                "Live Scan (предпросмотр распознавания)",
                style = MaterialTheme.typography.bodyMedium
            )
        }

        Spacer(Modifier.height(8.dp))

        // --- словарь имён ---
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.fillMaxWidth()
        ) {
            Checkbox(
                checked = syncNameDict,
                onCheckedChange = { syncNameDict = it }
            )
            Spacer(Modifier.width(8.dp))
            Text(
                "Словарь имён с сервера",
                style = MaterialTheme.typography.bodyMedium
            )
        }

        Spacer(Modifier.height(8.dp))

        // --- debug ---
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.fillMaxWidth()
        ) {
            Checkbox(
                checked = debugToasts,
                onCheckedChange = { enabled ->
                    debugToasts = enabled
                    onConfigChanged(config.copy(debugToasts = enabled))
                }
            )
            Spacer(Modifier.width(8.dp))
            Text(
                "Debug",
                style = MaterialTheme.typography.bodyMedium
            )
        }


        Spacer(Modifier.height(8.dp))

        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.fillMaxWidth()
        ) {
            Checkbox(
                checked = cameraModeEnabled,
                onCheckedChange = { enabled ->
                    cameraModeEnabled = enabled
                    onConfigChanged(config.copy(cameraModeEnabled = enabled))
                }
            )
            Spacer(Modifier.width(8.dp))
            Text(
                "Camera mode",
                style = MaterialTheme.typography.bodyMedium
            )
        }

        Spacer(Modifier.height(16.dp))

        Text(text = statusText)

        Spacer(Modifier.height(16.dp))

        // КНОПКА 1: ПРИВЯЗАТЬ
        Button(
            onClick = {
                if (serverUrl.isBlank()) {
                    statusText = "Укажи Server URL"
                    return@Button
                }

                isBusy = true
                statusText = "Отправляю запрос на привязку…"

                scope.launch {
                    try {
                        val updated = config.copy(
                            serverUrl = serverUrl.trim(),
                            deviceName = deviceName.trim(),
                            allowInsecureSsl = allowInsecure,
                            useRemoteOcr = useRemoteOcr,
                            liveScanEnabled = liveScanEnabled,
                            syncNameDict = syncNameDict,
                            debugToasts = debugToasts,
                            cameraModeEnabled = cameraModeEnabled
                        )

                        onConfigChanged(updated)

                        val result = enrollDeviceOnServer(context, updated)

                        if (result.ok && !result.deviceToken.isNullOrBlank()) {
                            statusText = "Устройство привязано"
                            onEnrollSuccess(result.deviceToken)
                        } else {
                            statusText =
                                "Ошибка привязки: ${result.errorMessage ?: "неизвестно"}"
                        }
                    } catch (e: Exception) {
                        e.printStackTrace()
                        statusText = "Сбой сети: ${e.message}"
                    } finally {
                        isBusy = false
                    }
                }
            },
            enabled = !isBusy,
            modifier = Modifier.fillMaxWidth()
        ) {
            Text("Привязать устройство")
        }

        Spacer(Modifier.height(8.dp))

        // КНОПКА 2: ОТВЯЗАТЬ
        Button(
            onClick = {
                isBusy = true
                statusText = "Отвязываю устройство…"

                scope.launch {
                    try {
                        // TODO: реальный HTTP /api/device_unenroll
                        delay(1000)
                        statusText = "Устройство отвязано"
                        onUnenroll()
                    } finally {
                        isBusy = false
                    }
                }
            },
            enabled = !isBusy && config.enrolled,
            colors = ButtonDefaults.buttonColors(
                containerColor = MaterialTheme.colorScheme.errorContainer,
                contentColor = MaterialTheme.colorScheme.onErrorContainer
            ),
            modifier = Modifier.fillMaxWidth()
        ) {
            Text("Отвязать устройство")
        }

        Spacer(Modifier.height(16.dp))

        OutlinedButton(
            onClick = { onClose() },
            modifier = Modifier.fillMaxWidth()
        ) {
            Text("Закрыть настройки")
        }
    }
}


@Composable
fun LoginReadyScreen(
    config: DeviceConfig,
    lastQr: String?,
    isLoggingIn: Boolean,
    loginError: String?,
    onScanQr: () -> Unit,
    modifier: Modifier = Modifier
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .padding(16.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        Image(
            painter = painterResource(id = R.drawable.cclogo),
            contentDescription = "CC logo",
            modifier = Modifier
                .fillMaxWidth()
                .height(96.dp)
                .padding(bottom = 12.dp),
            alignment = Alignment.Center
        )
        Text(
            text = "Устройство привязано к серверу:",
            style = MaterialTheme.typography.titleMedium
        )
        Spacer(modifier = Modifier.height(8.dp))
        Text(text = config.serverUrl)

        Spacer(modifier = Modifier.height(16.dp))

        Text(text = "Имя устройства: ${config.deviceName}")
        Text(text = "UID: ${config.deviceUid}")

        Spacer(modifier = Modifier.height(32.dp))

        Button(onClick = { onScanQr() }) {
            Text("Сканировать QR для входа")
        }

        Spacer(modifier = Modifier.height(24.dp))

        if (isLoggingIn) {
            Text(
                text = "Выполняю вход по QR…",
                style = MaterialTheme.typography.bodyMedium
            )
            Spacer(modifier = Modifier.height(8.dp))
        }

        if (!loginError.isNullOrBlank()) {
            Text(
                text = "Ошибка входа: $loginError",
                color = MaterialTheme.colorScheme.error,
                style = MaterialTheme.typography.bodyMedium
            )
            Spacer(modifier = Modifier.height(8.dp))
        }

        if (!lastQr.isNullOrBlank()) {
            Text(
                text = "Последний QR:\n$lastQr",
                style = MaterialTheme.typography.bodyMedium
            )
        }
    }
}

/**
 * Экран сканирования QR / штрихкодов.
 */
@Composable
fun QrScanScreen(
    modifier: Modifier = Modifier,
    onCodeScanned: (String) -> Unit,
    onCancel: () -> Unit
) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val prefs = remember {
        context.getSharedPreferences("scanner_camera_prefs", Context.MODE_PRIVATE)
    }
    val zoomPresets = remember { qrZoomPresets() }

    var selectedPresetId by remember {
        mutableStateOf(
            prefs.getString(QR_CAMERA_ZOOM_PRESET_KEY, defaultZoomPresetId(zoomPresets)) ?: defaultZoomPresetId(zoomPresets)
        )
    }

    val selectedPreset = remember(selectedPresetId, zoomPresets) {
        zoomPresets.firstOrNull { it.id == selectedPresetId } ?: zoomPresets.first { it.id == defaultZoomPresetId(zoomPresets) }
    }

    var hasCameraPermission by remember {
        mutableStateOf(
            ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.CAMERA
            ) == PackageManager.PERMISSION_GRANTED
        )
    }

    val permissionLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestPermission()
    ) { granted ->
        hasCameraPermission = granted
    }

    LaunchedEffect(Unit) {
        if (!hasCameraPermission) {
            permissionLauncher.launch(Manifest.permission.CAMERA)
        }
    }

    Box(
        modifier = modifier.fillMaxSize()
    ) {


        if (!hasCameraPermission) {
            Column(
                modifier = Modifier
                    .align(Alignment.Center)
                    .padding(16.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                Text("Нет доступа к камере")
                Spacer(Modifier.height(16.dp))
                OutlinedButton(onClick = { onCancel() }) {
                    Text("Назад")
                }
            }
        } else {
            // превью + сканер
            QrCameraPreview(
                lifecycleOwner = lifecycleOwner,
                modifier = Modifier.fillMaxSize(),
                selectedPreset = selectedPreset,
                onCodeScanned = onCodeScanned
            )

            Row(
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .padding(horizontal = 12.dp)
                    .padding(bottom = 76.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                zoomPresets.forEach { preset ->
                    val isSelected = preset.id == selectedPreset.id
                    FilledTonalButton(
                        onClick = {
                            selectedPresetId = preset.id
                            prefs.edit().putString(QR_CAMERA_ZOOM_PRESET_KEY, preset.id).apply()
                        },
                        colors = ButtonDefaults.filledTonalButtonColors(
                            containerColor = if (isSelected) {
                                MaterialTheme.colorScheme.primary
                            } else {
                                MaterialTheme.colorScheme.surfaceVariant
                            },
                            contentColor = if (isSelected) {
                                MaterialTheme.colorScheme.onPrimary
                            } else {
                                MaterialTheme.colorScheme.onSurfaceVariant
                            }
                        ),
                        contentPadding = PaddingValues(horizontal = 12.dp, vertical = 8.dp)
                    ) {
                        Text(text = preset.label)
                    }
                }
            }

            OutlinedButton(
                onClick = { onCancel() },
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .padding(16.dp)
            ) {
                Text("Отмена")
            }
        }
    }
}

/**
 * Обёртка над CameraX + ML Kit BarcodeScanning.
 * Берёт первый найденный код и отдаёт наверх.
 */

@Composable
fun QrCameraPreview(
    lifecycleOwner: LifecycleOwner,
    selectedPreset: CameraZoomPreset,
    onCodeScanned: (String) -> Unit,
    modifier: Modifier = Modifier
) {
    val context = LocalContext.current
    var camera by remember { mutableStateOf<Camera?>(null) }

    // PreviewView для CameraX
    val previewView = remember {
        PreviewView(context).apply {
            scaleType = PreviewView.ScaleType.FILL_CENTER
        }
    }

    LaunchedEffect(Unit) {
        val cameraProviderFuture = ProcessCameraProvider.getInstance(context)
        val cameraProvider = cameraProviderFuture.get()

        val preview = Preview.Builder()
            .build()
            .also { it.setSurfaceProvider(previewView.surfaceProvider) }

        val analysis = ImageAnalysis.Builder()
            .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
            .build()

        val options = BarcodeScannerOptions.Builder()
            .setBarcodeFormats(
                Barcode.FORMAT_QR_CODE,
                Barcode.FORMAT_AZTEC,
                Barcode.FORMAT_CODE_128,
                Barcode.FORMAT_CODE_39,
                Barcode.FORMAT_EAN_13,
                Barcode.FORMAT_EAN_8
            )
            .build()

        val scanner = BarcodeScanning.getClient(options)
        val executor = Executors.newSingleThreadExecutor()

        var found = false

        analysis.setAnalyzer(executor) { imageProxy ->
            if (found) {
                imageProxy.close()
                return@setAnalyzer
            }

            val mediaImage = imageProxy.image
            if (mediaImage == null) {
                imageProxy.close()
                return@setAnalyzer
            }

            val image = InputImage.fromMediaImage(
                mediaImage,
                imageProxy.imageInfo.rotationDegrees
            )

            scanner.process(image)
                .addOnSuccessListener { barcodes ->
                    val first = barcodes.firstOrNull()
                    val raw = first?.rawValue
                    if (!raw.isNullOrBlank()) {
                        found = true
                        onCodeScanned(raw)
                    }
                }
                .addOnFailureListener {
                    // можно залогировать ошибку, если надо
                }
                .addOnCompleteListener {
                    imageProxy.close()
                }
        }

        val selector = CameraSelector.DEFAULT_BACK_CAMERA

        try {
            cameraProvider.unbindAll()
            camera = cameraProvider.bindToLifecycle(
                lifecycleOwner,
                selector,
                preview,
                analysis
            )
        } catch (e: Exception) {
            e.printStackTrace()
        }
    }

    AndroidView(
        factory = { previewView },
        modifier = modifier
    )

    LaunchedEffect(camera, selectedPreset.zoomRatio) {
        val activeCamera = camera ?: return@LaunchedEffect
        val zoomState = activeCamera.cameraInfo.zoomState.value ?: return@LaunchedEffect
        val targetZoom = selectedPreset.zoomRatio.coerceIn(zoomState.minZoomRatio, zoomState.maxZoomRatio)
        activeCamera.cameraControl.setZoomRatio(targetZoom)
    }
}

private const val QR_CAMERA_ZOOM_PRESET_KEY = "qr_camera_zoom_preset"

data class CameraZoomPreset(
    val id: String,
    val label: String,
    val zoomRatio: Float
)

fun qrZoomPresets(): List<CameraZoomPreset> = listOf(
    CameraZoomPreset(id = "macro", label = "Макро", zoomRatio = 2.0f),
    CameraZoomPreset(id = "zoom", label = "2x", zoomRatio = 2.5f),
    CameraZoomPreset(id = "wide", label = "Широкий", zoomRatio = 0.7f),
    CameraZoomPreset(id = "normal", label = "1x", zoomRatio = 1.0f)
)

fun defaultZoomPresetId(presets: List<CameraZoomPreset>): String {
    return presets.firstOrNull { it.id == "normal" }?.id ?: presets.first().id
}

@Composable
fun CameraZoomButtonsVertical(
    modifier: Modifier = Modifier,
    zoomPresets: List<CameraZoomPreset>,
    selectedPresetId: String,
    onSelectPreset: (CameraZoomPreset) -> Unit
) {
    Column(
        modifier = modifier,
        verticalArrangement = Arrangement.spacedBy(8.dp)
    ) {
        zoomPresets.forEach { preset ->
            val isSelected = preset.id == selectedPresetId
            FilledTonalButton(
                onClick = { onSelectPreset(preset) },
                colors = ButtonDefaults.filledTonalButtonColors(
                    containerColor = if (isSelected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceVariant,
                    contentColor = if (isSelected) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.onSurfaceVariant
                ),
                contentPadding = PaddingValues(horizontal = 12.dp, vertical = 8.dp)
            ) {
                Text(text = preset.label)
            }
        }
    }
}

data class RemoteOcrResult(
    val ok: Boolean,
    val data: OcrParcelData?,
    val errorMessage: String?
)

suspend fun callRemoteOcrParse(
    context: Context,
    cfg: DeviceConfig,
    rawText: String
): RemoteOcrResult = withContext(Dispatchers.IO) {
    val baseUrl = cfg.serverUrl.trim()
    if (baseUrl.isEmpty()) {
        return@withContext RemoteOcrResult(false, null, "Пустой Server URL")
    }

    val urlStr = baseUrl.trimEnd('/') + "/api/ocr_parse.php"
    val url = URL(urlStr)
    val conn = (url.openConnection() as HttpURLConnection)

    // тот же трест SSL, что и в enroll/qr_login
    if (conn is HttpsURLConnection && cfg.allowInsecureSsl) {
        val trustAllCerts = arrayOf<TrustManager>(
            object : X509TrustManager {
                override fun checkClientTrusted(
                    chain: Array<X509Certificate>,
                    authType: String
                ) { }

                override fun checkServerTrusted(
                    chain: Array<X509Certificate>,
                    authType: String
                ) { }

                override fun getAcceptedIssuers(): Array<X509Certificate> =
                    emptyArray()
            }
        )

        val sslContext = SSLContext.getInstance("TLS")
        sslContext.init(null, trustAllCerts, SecureRandom())
        conn.sslSocketFactory = sslContext.socketFactory

        conn.hostnameVerifier = HostnameVerifier { _: String?, _: SSLSession? ->
            true
        }
    }

    try {
        conn.requestMethod = "POST"
        conn.connectTimeout = 5000
        conn.readTimeout = 5000
        conn.doOutput = true
        conn.setRequestProperty(
            "Content-Type",
            "application/x-www-form-urlencoded; charset=utf-8"
        )

        // PHP ждёт $_POST['raw_text'], значит шлём form-encoded
        val body = "raw_text=" + java.net.URLEncoder.encode(rawText, "UTF-8")
        conn.outputStream.use { os ->
            os.write(body.toByteArray(Charsets.UTF_8))
        }

        val code = conn.responseCode
        val stream: InputStream? =
            if (code in 200..299) conn.inputStream else conn.errorStream

        val respText = stream
            ?.bufferedReader(Charsets.UTF_8)
            ?.use { it.readText() }
            ?: ""

        if (respText.isBlank()) {
            return@withContext RemoteOcrResult(false, null, "Пустой ответ сервера (HTTP $code)")
        }

        val obj = try {
            JSONObject(respText)
        } catch (e: Exception) {
            e.printStackTrace()
            return@withContext RemoteOcrResult(false, null, "Некорректный JSON ответа")
        }

        val status = obj.optString("status", "error")
        if (status != "ok") {
            val msg = obj.optString("message", "status != ok")
            return@withContext RemoteOcrResult(false, null, msg)
        }

        val dataObj = obj.optJSONObject("data")
            ?: return@withContext RemoteOcrResult(false, null, "Нет поля data")

        fun optStringOrNull(name: String): String? =
            dataObj.optString(name, "").takeIf { it.isNotBlank() }

        val parcel = OcrParcelData(
            trackingNo = optStringOrNull("tracking_no"),
            receiverCountryCode = optStringOrNull("receiver_country_code"),
            receiverName = optStringOrNull("receiver_name"),
            receiverAddress = null, // php пока не отдаёт
            receiverCompany = optStringOrNull("receiver_company"),
            receiverForwarderCode = optStringOrNull("receiver_forwarder_code"),
            receiverCellCode = optStringOrNull("receiver_cell_code"),
            // NEW:
            localCarrierName = optStringOrNull("local_carrier_name"),
            localTrackingNo  = optStringOrNull("local_tracking_no"),

            senderName = null,
            weightKg = null,
            sizeL = null,
            sizeW = null,
            sizeH = null
        )

        RemoteOcrResult(true, parcel, null)
    } catch (e: Exception) {
        e.printStackTrace()
        RemoteOcrResult(false, null, e.message ?: "Ошибка соединения")
    } finally {
        conn.disconnect()
    }
}

data class EnrollResult(
    val ok: Boolean,
    val deviceToken: String?,
    val errorMessage: String?
)

/**
 * HTTP POST на <server_url>/api/device_enroll.php
 */
suspend fun enrollDeviceOnServer(
    context: Context,
    cfg: DeviceConfig
): EnrollResult = withContext(Dispatchers.IO) {
    val baseUrl = cfg.serverUrl.trim()
    if (baseUrl.isEmpty()) {
        return@withContext EnrollResult(false, null, "Пустой Server URL")
    }

    val urlStr = baseUrl.trimEnd('/') + "/api/device_enroll.php"
    val url = URL(urlStr)
    val conn = (url.openConnection() as HttpURLConnection)

    // TLS – разрешаем самоподписанный сертификат, если включено в настройках
    if (conn is HttpsURLConnection && cfg.allowInsecureSsl) {
        val trustAllCerts = arrayOf<TrustManager>(
            object : X509TrustManager {
                override fun checkClientTrusted(
                    chain: Array<X509Certificate>,
                    authType: String
                ) { }

                override fun checkServerTrusted(
                    chain: Array<X509Certificate>,
                    authType: String
                ) { }

                override fun getAcceptedIssuers(): Array<X509Certificate> =
                    emptyArray()
            }
        )

        val sslContext = SSLContext.getInstance("TLS")
        sslContext.init(null, trustAllCerts, SecureRandom())
        conn.sslSocketFactory = sslContext.socketFactory

        conn.hostnameVerifier = HostnameVerifier { _: String?, _: SSLSession? ->
            true
        }
    }

    try {
        conn.requestMethod = "POST"
        conn.connectTimeout = 5000
        conn.readTimeout = 5000
        conn.doOutput = true
        conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")

        // версия приложения
        val pm = context.packageManager
        val pInfo = pm.getPackageInfo(context.packageName, 0)
        val appVersion = pInfo.versionName ?: "unknown"

        // модель устройства
        val manufacturer = Build.MANUFACTURER ?: ""
        val model = Build.MODEL ?: ""
        val modelString = (manufacturer + " " + model).trim()

        val serial = ""

        val json = JSONObject().apply {
            put("mode", "enroll")
            put("device_uid", cfg.deviceUid)
            put("name", cfg.deviceName)
            put("serial", serial)
            put("model", modelString)
            put("app_version", appVersion)
        }

        val bodyBytes = json.toString().toByteArray(Charsets.UTF_8)
        conn.outputStream.use { os ->
            os.write(bodyBytes)
        }

        val code = conn.responseCode
        val stream = if (code in 200..299) conn.inputStream else conn.errorStream
        val respText = stream?.bufferedReader(Charsets.UTF_8)?.readText() ?: ""

        println("ENROLL resp code=$code body=$respText")

        if (respText.isBlank()) {
            return@withContext EnrollResult(false, null, "Пустой ответ сервера (HTTP $code)")
        }

        val obj = try {
            JSONObject(respText)
        } catch (e: Exception) {
            e.printStackTrace()
            return@withContext EnrollResult(false, null, "Некорректный JSON ответа")
        }

        val status = obj.optString("status", "error")
        if (status != "ok") {
            val msg = obj.optString(
                "message",
                obj.optString("messages", "status != ok")
            )
            return@withContext EnrollResult(false, null, msg)
        }

        val token = obj.optString("device_token", null)
        val isActive = obj.optInt("is_active", 0) // если нужно, можно где-то учитывать

        EnrollResult(true, token, null)
    } catch (e: Exception) {
        e.printStackTrace()
        EnrollResult(false, null, e.message ?: "Ошибка соединения")
    } finally {
        conn.disconnect()
    }
}

data class QrLoginResult(
    val ok: Boolean,
    val sessionId: String?,
    val errorMessage: String?
)

data class ApiCallResult(
    val ok: Boolean,
    val json: JSONObject?,
    val errorMessage: String?
)

data class QrCheckResult(
    val ok: Boolean,
    val cellId: String?,
    val cellCode: String?,
    val errorMessage: String?
)

data class MoveApplyResult(
    val ok: Boolean,
    val errorMessage: String?
)

/**
 * Execute a JavaScript function in WebView with retry logic and enhanced diagnostics.
 * If the function is not found, retries after a short delay.
 */
private fun executeWebFunctionWithRetry(
    web: WebView,
    escapedFunctionName: String,
    originalName: String,
    maxRetries: Int = 3,
    currentRetry: Int = 0,
    showToast: Boolean = false
) {
    val js = """
        (function(){
          var fnName = '$escapedFunctionName';
          var fnType = typeof window[fnName];
          
          if (fnType === 'function') {
            try {
              var result = window[fnName]();
              console.log('✓ Web op ' + fnName + '() returned:', result);
              return JSON.stringify({status: 'ok', result: result, retry: $currentRetry});
            } catch(e) {
              console.error('✗ Error in web op ' + fnName + '():', e);
              return JSON.stringify({status: 'error', error: String(e), retry: $currentRetry});
            }
          } else {
            // Enhanced diagnostics when function is missing
            var currentUrl = window.location.href;
            var similarKeys = Object.keys(window).filter(function(k) {
              return k.toLowerCase().includes('open') || k.toLowerCase().includes('move');
            }).slice(0, 10);
            
            console.error('✗ Web op function ' + fnName + ' not found');
            console.error('  typeof:', fnType);
            console.error('  URL:', currentUrl);
            console.error('  Similar keys:', similarKeys.join(', '));
            
            return JSON.stringify({
              status: 'not_found',
              fnName: fnName,
              fnType: fnType,
              url: currentUrl,
              similarKeys: similarKeys,
              retry: $currentRetry
            });
          }
        })();
    """.trimIndent()

    web.post {
        web.evaluateJavascript(js) { result ->
            println("### FlowOp.Web($originalName) [retry $currentRetry/$maxRetries] -> $result")

            // Parse result to check if we need to retry
            // Use regex for more robust JSON field matching
            val isNotFound = result?.matches(Regex(".*\"status\"\\s*:\\s*\"not_found\".*")) == true
            val shouldRetry = isNotFound && currentRetry < maxRetries

            if (shouldRetry) {
                // Wait 300ms and retry
                Handler(Looper.getMainLooper()).postDelayed({
                    println("### Retrying FlowOp.Web($originalName) after delay...")
                    executeWebFunctionWithRetry(web, escapedFunctionName, originalName, maxRetries, currentRetry + 1, showToast)
                }, 300)
            } else if (showToast) {
                // Show toast with result for context flows
                Handler(Looper.getMainLooper()).post {
                    val displayResult = when {
                        result?.matches(Regex(".*\"status\"\\s*:\\s*\"ok\".*")) == true -> "✓ Success"
                        result?.matches(Regex(".*\"status\"\\s*:\\s*\"error\".*")) == true -> "✗ Error"
                        isNotFound -> "✗ Not found after $currentRetry retries"
                        else -> result?.take(50) ?: "null"
                    }
                    debugToast(web.context, "JS $originalName -> $displayResult", Toast.LENGTH_LONG)
                }
            }
        }
    }
}
private fun applyInsecureSslIfNeeded(conn: HttpURLConnection, cfg: DeviceConfig) {
    if (conn is HttpsURLConnection && cfg.allowInsecureSsl) {
        val trustAllCerts = arrayOf<TrustManager>(
            object : X509TrustManager {
                override fun checkClientTrusted(chain: Array<X509Certificate>, authType: String) {}
                override fun checkServerTrusted(chain: Array<X509Certificate>, authType: String) {}
                override fun getAcceptedIssuers(): Array<X509Certificate> = emptyArray()
            }
        )
        val sslContext = SSLContext.getInstance("TLS")
        sslContext.init(null, trustAllCerts, SecureRandom())
        conn.sslSocketFactory = sslContext.socketFactory
        conn.hostnameVerifier = HostnameVerifier { _: String?, _: SSLSession? -> true }
    }
}

private fun buildApiUrl(baseUrl: String, endpoint: String): String {
    if (endpoint.startsWith("http://") || endpoint.startsWith("https://")) {
        return endpoint
    }
    val base = baseUrl.trimEnd('/')
    val path = if (endpoint.startsWith("/")) endpoint else "/$endpoint"
    return base + path
}

suspend fun callApiWithSessionCookie(
    cfg: DeviceConfig,
    endpoint: String,
    method: String = "POST",
    body: JSONObject
): ApiCallResult = withContext(Dispatchers.IO) {
    val baseUrl = normalizeServerUrl(cfg.serverUrl)
    if (baseUrl.isBlank()) {
        return@withContext ApiCallResult(false, null, "Пустой Server URL")
    }

    val urlStr = buildApiUrl(baseUrl, endpoint)
    val url = URL(urlStr)
    val conn = (url.openConnection() as HttpURLConnection)
    applyInsecureSslIfNeeded(conn, cfg)

    try {
        conn.requestMethod = method
        conn.connectTimeout = 5000
        conn.readTimeout = 5000
        conn.doOutput = true
        conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")

        val cookies = CookieManager.getInstance().getCookie(urlStr)
            ?: CookieManager.getInstance().getCookie(baseUrl)
        if (!cookies.isNullOrBlank()) {
            conn.setRequestProperty("Cookie", cookies)
        }

        val bodyBytes = body.toString().toByteArray(Charsets.UTF_8)
        conn.outputStream.use { it.write(bodyBytes) }

        val code = conn.responseCode
        val stream: InputStream? =
            if (code in 200..299) conn.inputStream else conn.errorStream

        val respText = stream?.bufferedReader(Charsets.UTF_8)?.readText() ?: ""
        if (respText.isBlank()) {
            return@withContext ApiCallResult(false, null, "Пустой ответ сервера (HTTP $code)")
        }

        val obj = try {
            JSONObject(respText)
        } catch (e: Exception) {
            e.printStackTrace()
            return@withContext ApiCallResult(false, null, "Некорректный JSON ответа")
        }

        ApiCallResult(true, obj, null)
    } catch (e: Exception) {
        e.printStackTrace()
        ApiCallResult(false, null, e.message ?: "Ошибка соединения")
    } finally {
        conn.disconnect()
    }
}


fun callApiCheck(
    scope: CoroutineScope,
    cfg: DeviceConfig,
    endpoint: String,
    rawValue: String,
    payloadKey: String
) {
    scope.launch {
        val payload = JSONObject().apply {
            put(payloadKey, rawValue)
        }
        callApiWithSessionCookie(cfg, endpoint, body = payload)
    }
}

suspend fun qrCheckWithSession(
    cfg: DeviceConfig,
    endpoint: String,
    qrRaw: String
): QrCheckResult {
    val payload = JSONObject().apply {
        put("qr", qrRaw)
    }
    val result = callApiWithSessionCookie(cfg, endpoint, body = payload)
    if (!result.ok) {
        return QrCheckResult(false, null, null, result.errorMessage)
    }
    val obj = result.json ?: return QrCheckResult(false, null, null, "Нет ответа сервера")
    val status = obj.optString("status", "error")
    if (status != "ok") {
        val msg = obj.optString("message", "status != ok")
        return QrCheckResult(false, null, null, msg)
    }
    val cellId = obj.optString("cell_id", "").takeIf { it.isNotBlank() }
    val cellCode = obj.optString("cell_code", "").takeIf { it.isNotBlank() }
    return QrCheckResult(true, cellId, cellCode, null)
}

suspend fun applyWarehouseMoveWithSession(
    cfg: DeviceConfig,
    endpoint: String,
    tracking: String,
    cellId: String,
    mode: String
): MoveApplyResult {
    val payload = JSONObject().apply {
        put("tracking", tracking)
        put("cell_id", cellId)
        put("mode", mode)
    }
    val result = callApiWithSessionCookie(cfg, endpoint, body = payload)
    if (!result.ok) {
        return MoveApplyResult(false, result.errorMessage)
    }
    val obj = result.json ?: return MoveApplyResult(false, "Нет ответа сервера")
    val status = obj.optString("status", "error")
    if (status != "ok") {
        val msg = obj.optString("message", "status != ok")
        return MoveApplyResult(false, msg)
    }
    return MoveApplyResult(true, null)
}
/**
 * Логин по QR:
 * POST /api/device_enroll.php
 * {
 *   "action": "qr_login",
 *   "device_uid":   "...",
 *   "device_token": "...",
 *   "qr_token":     "<строка из QR>",
 *   "app_version":  "1.0"
 * }
 */
suspend fun qrLoginOnServer(
    context: Context,
    cfg: DeviceConfig,
    qrToken: String
): QrLoginResult = withContext(Dispatchers.IO) {
    val baseUrl = cfg.serverUrl.trim()
    if (baseUrl.isEmpty()) {
        return@withContext QrLoginResult(false, null, "Пустой Server URL")
    }
    if (cfg.deviceToken.isNullOrBlank()) {
        return@withContext QrLoginResult(false, null, "Нет device_token (устройство не привязано)")
    }

    val urlStr = baseUrl.trimEnd('/') + "/api/device_enroll.php"
    val url = URL(urlStr)
    val conn = (url.openConnection() as HttpURLConnection)

    // TLS по-прежнему как у тебя:
    if (conn is HttpsURLConnection && cfg.allowInsecureSsl) {
        val trustAllCerts = arrayOf<TrustManager>(
            object : X509TrustManager {
                override fun checkClientTrusted(chain: Array<X509Certificate>, authType: String) {}
                override fun checkServerTrusted(chain: Array<X509Certificate>, authType: String) {}
                override fun getAcceptedIssuers(): Array<X509Certificate> = emptyArray()
            }
        )
        val sslContext = SSLContext.getInstance("TLS")
        sslContext.init(null, trustAllCerts, SecureRandom())
        conn.sslSocketFactory = sslContext.socketFactory
        conn.hostnameVerifier = HostnameVerifier { _: String?, _: SSLSession? -> true }
    }

    try {
        conn.requestMethod = "POST"
        conn.connectTimeout = 5000
        conn.readTimeout = 5000
        conn.doOutput = true
        conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")

        val pm = context.packageManager
        val pInfo = pm.getPackageInfo(context.packageName, 0)
        val appVersion = pInfo.versionName ?: "unknown"

        val json = JSONObject().apply {
            put("mode", "qr_login")
            put("device_uid", cfg.deviceUid)
            put("device_token", cfg.deviceToken)
            put("qr_token", qrToken)
            put("app_version", appVersion)
        }

        val bodyBytes = json.toString().toByteArray(Charsets.UTF_8)
        conn.outputStream.use { it.write(bodyBytes) }

        val code = conn.responseCode
        val stream: InputStream? =
            if (code in 200..299) conn.inputStream else conn.errorStream

        val respText = stream
            ?.bufferedReader(Charsets.UTF_8)
            ?.use { it.readText() }
            ?: ""

        if (respText.isBlank()) {
            return@withContext QrLoginResult(false, null, "Пустой ответ сервера (HTTP $code)")
        }

        val obj = try {
            JSONObject(respText)
        } catch (e: Exception) {
            e.printStackTrace()
            return@withContext QrLoginResult(false, null, "Некорректный JSON ответа")
        }

        val status = obj.optString("status", "error")
        if (status != "ok") {
            val msg = obj.optString("message", "status != ok")
            return@withContext QrLoginResult(false, null, msg)
        }

        // сервер теперь отдаёт session_id + user_id + role
        val sessionId = obj.optString("session_id", null)
        if (sessionId.isNullOrBlank()) {
            return@withContext QrLoginResult(false, null, "session_id не вернулся от сервера")
        }

        QrLoginResult(true, sessionId, null)
    } catch (e: Exception) {
        e.printStackTrace()
        QrLoginResult(false, null, e.message ?: "Ошибка соединения")
    } finally {
        conn.disconnect()
    }
}

fun parseOcrTemplates(json: String): OcrTemplates? = try {
    val root = JSONObject(json)
    val version = root.optInt("version", 1)
    val carriersObj = root.optJSONObject("carriers") ?: return null

    val carriersMap = mutableMapOf<String, CarrierTemplate>()

    val carrierNames = carriersObj.keys()
    while (carrierNames.hasNext()) {
        val code = carrierNames.next()

        val carrierVal = carriersObj.opt(code)
        val cObj: JSONObject = when (carrierVal) {
            is JSONObject -> carrierVal
            is org.json.JSONArray -> carrierVal.optJSONObject(0) ?: continue
            else -> continue
        }


        val displayName = cObj.optString("display_name", code)
        val rulesObj = cObj.optJSONObject("rules") ?: JSONObject()

        val rulesMap = mutableMapOf<String, RuleConfig>()
        val ruleNames = rulesObj.keys()
        while (ruleNames.hasNext()) {
            val rk = ruleNames.next()
            val rObj = rulesObj.optJSONObject(rk) ?: continue

            val type = rObj.optString("type", "")
            if (type.isBlank()) continue

            val pattern = if (rObj.has("pattern")) rObj.optString("pattern") else null
            val lines = if (rObj.has("lines")) rObj.optInt("lines") else null

            val mvArr = rObj.optJSONArray("marker_variants")
            val markerVariants = mutableListOf<String>()
            if (mvArr != null) {
                for (i in 0 until mvArr.length()) markerVariants += mvArr.optString(i)
            }

            rulesMap[rk] = RuleConfig(
                type = type,
                pattern = pattern,
                markerVariants = markerVariants.takeIf { it.isNotEmpty() },
                lines = lines
            )
        }

        carriersMap[code] = CarrierTemplate(
            displayName = displayName,
            rules = rulesMap
        )
    }

    OcrTemplates(version = version, carriers = carriersMap)
} catch (e: Exception) {
    e.printStackTrace()
    null
}


private fun createCameraCaptureIntent(context: Context): Pair<Intent, Uri>? {
    return try {
        val imagesDir = File(context.cacheDir, "webview_captures").apply { mkdirs() }
        val imageFile = File.createTempFile("capture_", ".jpg", imagesDir)
        val authority = "${context.packageName}.fileprovider"
        val uri = FileProvider.getUriForFile(context, authority, imageFile)

        val intent = Intent(MediaStore.ACTION_IMAGE_CAPTURE).apply {
            putExtra(MediaStore.EXTRA_OUTPUT, uri)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION)
        }
        Pair(intent, uri)
    } catch (_: Exception) {
        null
    }
}

private fun clearWebViewDataOnStart(context: Context) {
    val webView = WebView(context)
    try {
        webView.clearCache(true)
    } catch (_: Exception) {
    }
    try {
        webView.clearHistory()
    } catch (_: Exception) {
    }
    try {
        WebStorage.getInstance().deleteAllData()
    } catch (_: Exception) {
    }
    try {
        CookieManager.getInstance().removeAllCookies(null)
        CookieManager.getInstance().flush()
    } catch (_: Exception) {
    }
}

@Composable
fun DeviceWebViewScreen(
    config: DeviceConfig,
    shouldClearWebViewData: Boolean,
    onWebViewDataCleared: () -> Unit,
    onWebViewReady: (WebView) -> Unit,
    onSessionEnded: () -> Unit,
    modifier: Modifier = Modifier,
    onTemplatesLoaded: (OcrTemplates?) -> Unit,
    onNameDictLoaded: (NameDict?) -> Unit,   // <<< НОВЫЙ колбэк
    onContextUpdated: (taskJson: String?, tmplJson: String?, destJson: String?, dictJson: String?) -> Unit

) {
    var filePathCallback by remember { mutableStateOf<ValueCallback<Array<Uri>>?>(null) }
    var pendingCaptureUri by remember { mutableStateOf<Uri?>(null) }
    val fileChooserLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.StartActivityForResult()
    ) { result ->
        val callback = filePathCallback
        val captureUri = pendingCaptureUri
        filePathCallback = null
        pendingCaptureUri = null
        if (callback == null) return@rememberLauncherForActivityResult

        val uris = if (result.resultCode == Activity.RESULT_OK) {
            val parsed = WebChromeClient.FileChooserParams.parseResult(result.resultCode, result.data)
            when {
                parsed != null && parsed.isNotEmpty() -> parsed
                captureUri != null -> arrayOf(captureUri)
                else -> emptyArray()
            }
        } else {
            emptyArray()
        }
        callback.onReceiveValue(uris)
    }


    AndroidView(
        modifier = modifier.fillMaxSize(),
        factory = { ctx ->
            WebView(ctx).apply {
                val mainHandler = Handler(Looper.getMainLooper())

                class DeviceBridge {
                    @JavascriptInterface
                    fun onMainContext(payload: String?) {
                        if (payload.isNullOrBlank()) return
                        try {
                            val obj = JSONObject(payload)

                            mainHandler.post {
                                fun clean(v: String?): String? {
                                    val s = v?.trim()
                                    if (s.isNullOrEmpty()) return null
                                    if (s.equals("null", true) || s.equals("undefined", true)) return null
                                    return s
                                }

                                val task = clean(obj.optString("task", null))
                                val tmpl = clean(obj.optString("ocr_templates", null))
                                val dest = clean(obj.optString("destcountry", null))
                                val dict = clean(obj.optString("dicts", null))
                                onContextUpdated(task, tmpl, dest, dict)
                            }
                        } catch (_: Exception) {}
                    }
                }

                addJavascriptInterface(DeviceBridge(), "DeviceApp")
                layoutParams = ViewGroup.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.MATCH_PARENT
                )

                settings.javaScriptEnabled = true
                settings.domStorageEnabled = true
                settings.mediaPlaybackRequiresUserGesture = false

                // Clear WebView cache/storage only when requested (app start)
                if (shouldClearWebViewData) {
                    onWebViewDataCleared()
                    settings.cacheMode = WebSettings.LOAD_NO_CACHE

                    // force clean caches (helps with stale core_api.js / scripts)
                    try {
                        clearCache(true)
                    } catch (_: Exception) {
                    }
                    try {
                        clearHistory()
                    } catch (_: Exception) {
                    }
                    try {
                        WebStorage.getInstance().deleteAllData()
                    } catch (_: Exception) {
                    }
                    try {
                        CookieManager.getInstance().removeAllCookies(null)
                        CookieManager.getInstance().flush()
                    } catch (_: Exception) {
                    }
                }

                webChromeClient = object : WebChromeClient() {
                    override fun onShowFileChooser(
                        webView: WebView?,
                        chooserCallback: ValueCallback<Array<Uri>>?,
                        fileChooserParams: FileChooserParams?
                    ): Boolean {
                        filePathCallback?.onReceiveValue(null)
                        filePathCallback = chooserCallback

                        if (chooserCallback == null) return false

                        val captureEnabled = fileChooserParams?.isCaptureEnabled == true
                        val acceptsImage = fileChooserParams
                            ?.acceptTypes
                            ?.any { it?.contains("image", ignoreCase = true) == true } == true

                        val intentToLaunch = if (captureEnabled && acceptsImage) {
                            createCameraCaptureIntent(context)?.also { (intent, uri) ->
                                pendingCaptureUri = uri
                            }?.first
                        } else {
                            pendingCaptureUri = null
                            try {
                                fileChooserParams?.createIntent()
                            } catch (_: Exception) {
                                null
                            }
                        }

                        if (intentToLaunch == null) {
                            filePathCallback = null
                            pendingCaptureUri = null
                            chooserCallback.onReceiveValue(null)
                            return false
                        }

                        return try {
                            fileChooserLauncher.launch(intentToLaunch)
                            true
                        } catch (_: Exception) {
                            filePathCallback = null
                            pendingCaptureUri = null
                            chooserCallback.onReceiveValue(null)
                            false
                        }
                    }
                }


                webViewClient = object : WebViewClient() {

                    private var firstPageLoaded = false

                    override fun onReceivedSslError(
                        view: WebView?,
                        handler: SslErrorHandler?,
                        error: SslError?
                    ) {
                        // как было раньше: игнорируем SSL, если включен флаг
                        if (config.allowInsecureSsl) {
                            handler?.proceed()
                        } else {
                            handler?.cancel()
                        }
                    }

                    override fun onPageFinished(view: WebView?, url: String?) {
                        super.onPageFinished(view, url)

                        if (!firstPageLoaded && view != null) {
                            firstPageLoaded = true
                            onWebViewReady(view)
                          ////  view.evaluateJavascript(INSTALL_MAIN_OBSERVER_JS, null)
                        }
                        // ВАЖНО: всегда инжектим, потому что при реальном reload JS улетает
                        view?.evaluateJavascript(INSTALL_MAIN_OBSERVER_JS, null)

                        val base = normalizeServerUrl(config.serverUrl)
                        if (!url.isNullOrBlank() && base.isNotBlank()) {
                            val u = Uri.parse(url)
                            val b = Uri.parse(base)

                            val sameHost = (u.scheme == b.scheme) && (u.host == b.host) && (u.port == b.port)
                            val path = u.path ?: ""

                            // считаем "сессия закончилась" только если реально открылась страница логина
                            if (sameHost && (path == "/login" || path.startsWith("/login/"))) {
                                onSessionEnded()
                            }
                        }
                    }
                }

                val base = normalizeServerUrl(config.serverUrl)
                val startUrl = if (base.isNotEmpty()) "$base/main" else "about:blank"
                loadUrl(startUrl)
                // ВАЖНО: onWebViewReady здесь НЕ вызываем, только в onPageFinished
            }
        }
    )
}

data class DestForwarder(
    val code: String,
    val name: String,
    val aliases: List<String>
)

data class DestCountryCfg(
    val id: Int,
    val code_iso2: String,
    val code_iso3: String,
    val name_en: String,
    val name_local: String,
    val aliases: List<String>,
    val forwarders: List<DestForwarder>
)

data class DetectedDest(
    val countryCode: String?,
    val countryName: String?,
    val forwarderCode: String?,
    val forwarderName: String?
)


// ---- OCR-шаблоны, которые прилетают с сервера через скрытый div ----

data class OcrTemplates(
    val version: Int,
    val carriers: Map<String, CarrierTemplate>
)

data class CarrierTemplate(
    val displayName: String,
    val rules: Map<String, RuleConfig>
)

data class RuleConfig(
    val type: String,
    val pattern: String? = null,
    val markerVariants: List<String>? = null,
    val lines: Int? = null
)



data class NameDict(
    val version: Int,
    val exactBad: List<String>,
    val substrBad: List<String>
)

fun defaultNameDict(): NameDict = NameDict(
    version = 1,
    exactBad = listOf(
        "llc","gmbh","gimbh","cmr",
        "telefon","kontakt","datum",
        "paketschein","paket","fremdbarcode",
        "time","weight","de","d","co2","kg","kg paket",
        "absender","absenderin/sender",
        "postleitzahl","postleitzanl","postleitzah","postieitzah","pastleitzahl",
        "day","dhl","phl","ed","h&m",
        "mainz","hamm","nürtingen","nuertingen","leitcode routingcode",
        "rack","gusensberg",
        "vus chland + eu","vuschland + eu","vuschland+eu","vuschland",
        "herrn","llg",
        "desc, cosmetics","desc","cosmetics",
        "expres","cos",
        "hermes","hhermes","ghermnes",
        "xun","|am","|an","|an:",
        "fron ce","do not use for returns","postage paid",
        "puma","ainz","koli",
        "revolution beauty","sunday natural products",
        "paket nr","frmeswe do cg",
        "apo pharmacy b.v","apo pharmacy b v",
        "kunden nr","gewicht in kg",
        "billing no","yor gls track",
        "cust id","customer id",
        "ioerffl deh holldore",
        "service sperrgut aencorbrant","service sperrgut","sperrgut",
        "fedex aerm eny",
        "cho gxo supply chain","co gxo supply chain",
        "koliexp","orthopädie geld","we lg h","contsct",
        "delivery address","deiivery address",
        "entglt ezaht","mehr kommfort ein","dror code"
    ),
    substrBad = listOf(
        " llc"," gmbh"," gimbh","gm bh",
        " online"," shop"," lounge"," hub",
        " paket","päckchen","paketschein"," gewicht","anzahl",
        " datum","kontakt"," telefon","contsct",
        "postleit","fremdbarcode","id no","cust.","customer",
        "shipment","sendungs","abrechnungsnr","referenznr","ref.",
        "epg one","co2","emiss","we lg h",
        "starkenburgstr","starkenburgstraße"," str.","straße","strasse",
        "deutschland","germany","hessen",
        "mörfelden","morfelden","harfelden","nharfelden","walldorf","wal ldorf","unna",
        "kg paket","ups s tandard","ups standard","dp ag",
        "warenpost","parcel connect",
        "billing p/p","biling. p/p","biling p/p","billing no"," billing",
        "wir reduzierer","pl ieutschiond",
        "wir kompensieren","wir kompens","wir kormip","wir kormi",
        "labex","l.t.d",
        "empfanger","empfänger","sender","leitcode routingcode",
        "online - shop","ioerffl deh holldore",
        "veepee","best secret","best secrel","dhl hub",
        "inditex"," zara","zalando lounge","zalando se","zalando ",
        "deutsche post","post dhl hub","|am",
        "c/o deutsche post","c/o dhl","c/o ",
        "nürtingen","nuertingen","mainz","hamm","gusensberg",
        " vound nachname","vor- und nachname","nachname",
        " gewicht","gew'cht","gew.cht","do not use for returns",
        " empf nger","empf nger","empfi nger","empfaenger","empfänger",
        " kundenreferenz","notiz",
        " orthopädie","orthopädie-geld",
        " poing","potsdam","krefeld","magdeburg","neum ark","neumark",
        " @rmany"," germany","deutschland",
        "kunden nr","paket nr","frmeswe do cg",
        "asos"," we do","we-do","ve do","ve do!",
        "es naalbaceie",
        "revolution beauty"," beauty",
        "sunday natural products"," natural products"," products",
        "inklusive nachhaltigem versand"," nachhaltigem versand"," versand",
        "apo pharmacy"," pharmacy",
        "autosevice baudisch","autosevice","baudisch",
        "gxo supply chain"," supply chain",
        "rel nasee. ret","rel nasee","destnstire","orthopädie geld",
        "absen der","koliexp","yor gls track",
        "gewicht in kg","|an","|an:","fron ce",
        "apo pharmacy b.v","apo pharmacy b v",
        "service sperrgut aencorbrant","service sperrgut","sperrgut",
        "inklusive nachhaltigem versand",
        "billing no",
        "desc, cosmetics","desc","cosmetics",
        "cust id","mehr kommfort ein","postage paid",
        "empfång","entglt","entgelt",
        "dror code","fedex aerm eny",
        "siehe rückseite","unter dhl.de","mit dhl"
    )
)

fun parseNameDictJson(json: String): NameDict? {
    return try {
        val obj = JSONObject(json)

        val version = obj.optInt("version", 1)

        fun readArray(name: String): List<String> {
            val arr = obj.optJSONArray(name) ?: return emptyList()
            val res = mutableListOf<String>()
            for (i in 0 until arr.length()) {
                val v = arr.optString(i, "").trim()
                if (v.isNotEmpty()) {
                    res += v.lowercase()
                }
            }
            return res
        }

        NameDict(
            version = version,
            exactBad = readArray("exact_bad"),
            substrBad = readArray("substr_bad")
        )
    } catch (e: Exception) {
        e.printStackTrace()
        null
    }
}

data class OcrParcelData(
    val tuid: String? = null,              // <--- NEW
    val trackingNo: String? = null,

    // ISO2, например "AZ", "DE", "KG"
    val receiverCountryCode: String? = null,

    val receiverName: String? = null,
    val receiverAddress: String? = null,

    // компания-форвардер (Camex, Colibri Express и т.д.)
    val receiverCompany: String? = null,

    // машинный код форвардера (CAMEX, COLIBRI, ASER …)
    val receiverForwarderCode: String? = null,

    // номер ячейки A66050 / AS228905 / C163361 …
    val receiverCellCode: String? = null,

    val senderName: String? = null,
    val weightKg: Double? = null,
    val sizeL: Double? = null,
    val sizeW: Double? = null,
    val sizeH: Double? = null,

    // NEW: локальный перевозчик (DHL/GLS/HERMES/UPS/AMAZON) и его трек
    val localCarrierName: String? = null,
    val localTrackingNo: String? = null

)

fun escapeJsString(input: String): String =
    input.replace("\\", "\\\\")
        .replace("'", "\\'")
        .replace("\n", " ")
        .replace("\r", " ")

fun escapeJsSelector(input: String): String =
    input.replace("\\", "\\\\")
        .replace("\"", "\\\"")

fun setInputValueBySelector(webView: WebView, selector: String, value: String) {
    val js = """
        (function(){
          var el = document.querySelector('${escapeJsString(selector)}');
          if (!el) return;
          el.value = '${escapeJsString(value)}';
          el.dispatchEvent(new Event('input',{bubbles:true}));
          el.dispatchEvent(new Event('change',{bubbles:true}));
        })();
    """.trimIndent()
    webView.post { webView.evaluateJavascript(js, null) }
}

fun setActiveElementValue(webView: WebView, value: String) {
    val js = """
        (function(){
          var el = document.activeElement;
          if (!el) return;
          var tag = (el.tagName || '').toUpperCase();
          if (el.isContentEditable) {
            el.innerText = '${escapeJsString(value)}';
          } else if (tag === 'INPUT' || tag === 'TEXTAREA') {
            el.value = '${escapeJsString(value)}';
          } else {
            return;
          }
          el.dispatchEvent(new Event('input',{bubbles:true}));
          el.dispatchEvent(new Event('change',{bubbles:true}));
        })();
    """.trimIndent()
    webView.post { webView.evaluateJavascript(js, null) }
}

fun clearActiveElementValue(webView: WebView) {
    setActiveElementValue(webView, "")
}


fun getInputValueBySelector(webView: WebView, selector: String, onResult: (String?) -> Unit) {
    val js = """
        (function(){
          var el = document.querySelector('${escapeJsString(selector)}');
          return el ? (el.value || '') : '';
        })();
    """.trimIndent()
    webView.post {
        webView.evaluateJavascript(js) { raw ->
            val normalized = raw?.trim()?.trim('"')
            onResult(normalized)
        }
    }
}

fun setSelectValueById(webView: WebView, selectId: String, value: String) {
    val js = """
        (function(){
          var el = document.getElementById('${escapeJsString(selectId)}');
          if (!el) return;
          el.value = '${escapeJsString(value)}';
          el.dispatchEvent(new Event('input',{bubbles:true}));
          el.dispatchEvent(new Event('change',{bubbles:true}));
        })();
    """.trimIndent()
    webView.post { webView.evaluateJavascript(js, null) }
}

fun clickElementBySelector(webView: WebView, selector: String) {
    val js = """
        (function(){
          var el = document.querySelector('${escapeJsString(selector)}');
          if (el && typeof el.click === 'function') {
            el.click();
            return true;
          }
          return false;
        })();
    """.trimIndent()
    webView.post { webView.evaluateJavascript(js, null) }
}

fun clearWarehouseMoveResultsInWebView(webView: WebView) {
    val js = """
        (function(){
          var tbody = document.getElementById('warehouse-move-results-tbody');
          if (tbody) tbody.innerHTML = '';
        })();
    """.trimIndent()
    webView.post { webView.evaluateJavascript(js, null) }
}

fun openWarehouseMoveModalInWebView(webView: WebView) {
    val js = """
        (function(){
          try {
            var tbody = document.getElementById('warehouse-move-results-tbody');
            if (!tbody) return false;
            var el = tbody.querySelector('.js-core-link[data-core-action="warehouse_move_open_modal"]');
            if (!el) return false;
            var itemId = el.getAttribute('data-item-id') || el.getAttribute('data-item_id');
            if (!itemId) return false;
            if (window.CoreAPI && CoreAPI.client && CoreAPI.client.call) {
              var fd = new FormData();
              fd.append('action', 'warehouse_move_open_modal');
              fd.append('item_id', itemId);
              CoreAPI.client.call(fd)
                .then(function(data){
                  try {
                    if (!data || data.status !== 'ok') {
                      console.error('openMoveModal: bad response', data);
                      return;
                    }
                    var handler = CoreAPI.handlers && (CoreAPI.handlers['warehouse_move_open_modal'] || CoreAPI.handlers['default']);
                    if (handler) handler(data, el, fd);
                  } catch (e) {
                    console.error('openMoveModal handler failed:', e);
                  }
                })
                .catch(function(e){
                  console.error('openMoveModal request failed:', e);
                });
              return true;
            }
            if (typeof el.click === 'function') {
              el.click();
              return true;
            }
            return false;
          } catch (e) {
            console.error('openMoveModal failed:', e);
            return false;
          }
        })();
    """.trimIndent()
    webView.post { webView.evaluateJavascript(js, null) }
}

fun triggerWarehouseMoveSaveInWebView(webView: WebView) {
    val js = """
        (function(){
          try {
            var saveBtn = document.querySelector('button.js-core-link[data-core-action="warehouse_move_save_cell"]');
            if (!saveBtn) return false;
            var action = saveBtn.getAttribute('data-core-action');
            if (window.CoreAPI && CoreAPI.sendRequest && action) {
              var form = saveBtn.closest('form');
              if (form) {
                var formData = new FormData(form);
                var params = {};
                for (var pair of formData.entries()) {
                  params[pair[0]] = pair[1];
                }
                CoreAPI.sendRequest(action, params);
                return true;
              }
            }
            if (typeof saveBtn.click === 'function') {
              saveBtn.click();
              return true;
            }
            return false;
          } catch (e) {
            console.error('triggerSaveButton failed:', e);
            return false;
          }
        })();
    """.trimIndent()
    webView.post { webView.evaluateJavascript(js, null) }
}

fun confirmWarehouseMoveBatchInWebView(webView: WebView) {
    val js = """
        (function(){
          try {
            var cellSelect = document.getElementById('warehouse-move-batch-cell');
            if (!cellSelect || !cellSelect.value) return false;
            var tbody = document.getElementById('warehouse-move-batch-results-tbody');
            if (!tbody) return false;
            var buttons = tbody.querySelectorAll('.js-warehouse-move-batch-action[data-core-action="warehouse_move_batch_assign"]');
            if (!buttons || buttons.length !== 1) return false;
            var button = buttons[0];
            var itemId = button.getAttribute('data-item-id');
            if (!itemId) return false;
            if (button instanceof HTMLButtonElement && button.disabled) return false;
            var formData = new FormData();
            formData.append('action', 'warehouse_move_batch_assign');
            formData.append('item_id', itemId);
            formData.append('cell_id', cellSelect.value);
            if (window.CoreAPI && CoreAPI.client && CoreAPI.client.call) {
              CoreAPI.client.call(formData)
                .then(function(data){
                  if (!data || data.status !== 'ok') {
                    console.error('core_api error:', data);
                    return;
                  }
                  var handler = CoreAPI.handlers && (CoreAPI.handlers.warehouse_move_batch_assign || CoreAPI.handlers.default);
                  if (handler) handler(data);
                })
                .catch(function(err){
                  console.error('core_api fetch error:', err);
                });
              return true;
            }
            if (typeof button.click === 'function') {
              button.click();
              return true;
            }
            return false;
          } catch (e) {
            console.error('confirmBatchMove failed:', e);
            return false;
          }
        })();
    """.trimIndent()
    webView.post { webView.evaluateJavascript(js, null) }
}

fun triggerToolsManagementSaveInWebView(webView: WebView) {
    clickElementBySelector(webView, "button.js-core-link[data-core-action=\"tools_management_save_move\"]")
}

fun setWarehouseMoveCellFromQrInWebView(webView: WebView, qrValue: String) {
    val js = """
        (function(){
          var cellCode = String('${escapeJsString(qrValue)}' || '').trim();
          if (!cellCode) return false;
          if (cellCode.toUpperCase().startsWith('CELL:')) cellCode = cellCode.slice(5).trim();
          if (!cellCode) return false;
          function trySet(tries){
            var cellSelect = document.getElementById('cellId');
            if (!cellSelect) {
              if (tries > 0) return setTimeout(function(){ trySet(tries - 1); }, 300);
              return false;
            }
            var want = cellCode.toUpperCase();
            var found = null;
            for (var i = 0; i < cellSelect.options.length; i++) {
              var opt = cellSelect.options[i];
              if ((opt.text || '').trim().toUpperCase() === want) { found = opt; break; }
            }
            if (!found) return false;
            cellSelect.value = found.value;
            cellSelect.dispatchEvent(new Event('change', { bubbles: true }));
            cellSelect.dispatchEvent(new Event('input', { bubbles: true }));
            return true;
          }
          return trySet(5);
        })();
    """.trimIndent()
    webView.post { webView.evaluateJavascript(js, null) }
}

fun setToolsUserFromQrInWebView(webView: WebView, qrValue: String) {
    val js = """
        (function(){
          try {
            function extractTokenFromQr(rawValue) {
              var raw = String(rawValue || '').trim();
              if (!raw) return '';
              var normalized = raw.replace(/\s+/g, ' ').trim();
              var upper = normalized.toUpperCase();
              if (upper.startsWith('USER:')) return normalized.slice(5).trim();
              var urlMatch = normalized.match(/[?&]token=([0-9a-fA-F]{16,64})/);
              if (urlMatch) return urlMatch[1];
              var hexMatch = normalized.match(/[0-9a-fA-F]{32}/);
              if (hexMatch) return hexMatch[0];
              return normalized;
            }
            function withSelectRetry(selectId, handler, tries, delay) {
              var select = document.getElementById(selectId);
              if (select) return handler(select);
              if (tries <= 0) return false;
              return setTimeout(function(){ withSelectRetry(selectId, handler, tries - 1, delay); }, delay);
            }
            var token = extractTokenFromQr('${escapeJsString(qrValue)}');
            if (!token) return false;
            return withSelectRetry('toolAssignedUser', function(select){
              var tokenUpper = token.toUpperCase();
              var found = null;
              for (var i = 0; i < select.options.length; i++) {
                var opt = select.options[i];
                var optToken = (opt.getAttribute('data-qr-token') || '').trim();
                if (optToken && optToken.toUpperCase() === tokenUpper) { found = opt; break; }
              }
              if (!found && /^\d+$/.test(token)) {
                for (var i = 0; i < select.options.length; i++) {
                  var opt = select.options[i];
                  if (String(opt.value) === token) { found = opt; break; }
                }
              }
              if (!found) return false;
              select.value = found.value;
              select.dispatchEvent(new Event('change', { bubbles: true }));
              select.dispatchEvent(new Event('input', { bubbles: true }));
              return true;
            }, 15, 300);
          } catch (e) {
            console.error('setToolsUserFromQR error:', e);
            return false;
          }
        })();
    """.trimIndent()
    webView.post { webView.evaluateJavascript(js, null) }
}

fun setToolsCellFromQrInWebView(webView: WebView, qrValue: String) {
    val js = """
        (function(){
          try {
            function extractCellCodeFromQr(rawValue) {
              var raw = String(rawValue || '').trim();
              if (!raw) return '';
              var normalized = raw.replace(/\s+/g, ' ').trim();
              var upper = normalized.toUpperCase();
              if (upper.startsWith('CELL:')) return normalized.slice(5).trim();
              var queryMatch = normalized.match(/[?&]cell(?:_code|_id)?=([^&#]+)/i);
              if (queryMatch) return decodeURIComponent(queryMatch[1]).trim();
              var pathMatch = normalized.match(/\/cells?\/([^/?#]+)/i);
              if (pathMatch) return decodeURIComponent(pathMatch[1]).trim();
              try {
                var url = new URL(normalized);
                var cellParam = url.searchParams.get('cell') || url.searchParams.get('cell_code') || url.searchParams.get('cell_id');
                if (cellParam) return cellParam.trim();
                var pathname = url.pathname || '';
                var segments = pathname.split('/').filter(Boolean);
                if (segments.length) return decodeURIComponent(segments[segments.length - 1]).trim();
              } catch (e) {
                // ignore
              }
              var tokens = normalized.match(/[A-Za-z0-9_-]+/g);
              if (tokens && tokens.length) return tokens[tokens.length - 1];
              return normalized;
            }
            function normalizeCellCode(value) {
              return String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
            }
            function withSelectRetry(selectId, handler, tries, delay) {
              var select = document.getElementById(selectId);
              if (select) return handler(select);
              if (tries <= 0) return false;
              return setTimeout(function(){ withSelectRetry(selectId, handler, tries - 1, delay); }, delay);
            }
            var cellCode = extractCellCodeFromQr('${escapeJsString(qrValue)}');
            if (!cellCode) return false;
            var normalized = cellCode.replace(/\s+/g, ' ').trim();
            var upper = normalized.toUpperCase();
            if (upper.startsWith('CELL:')) cellCode = normalized.slice(5).trim();
            var codeMatch = cellCode.match(/[A-Za-z0-9_-]+/);
            if (codeMatch) cellCode = codeMatch[0];
            if (!cellCode) return false;
            return withSelectRetry('toolStorageCell', function(select){
              var want = normalizeCellCode(cellCode);
              for (var i = 0; i < select.options.length; i++) {
                var opt = select.options[i];
                var text = normalizeCellCode(opt.text);
                var value = normalizeCellCode(opt.value);
                if (text === want || value === want) {
                  select.value = opt.value;
                  select.dispatchEvent(new Event('change', { bubbles: true }));
                  select.dispatchEvent(new Event('input', { bubbles: true }));
                  return true;
                }
              }
              return false;
            }, 15, 300);
          } catch (e) {
            console.error('setToolsCellFromQR error:', e);
            return false;
          }
        })();
    """.trimIndent()
    webView.post { webView.evaluateJavascript(js, null) }
}

fun resolveActiveContextId(
    webView: WebView,
    contexts: Map<String, ScanContextConfig>,
    onResult: (String?) -> Unit
) {
    val entries = contexts.entries.toList()
    fun checkIndex(index: Int) {
        if (index >= entries.size) {
            onResult(null)
            return
        }
        val entry = entries[index]
        val selector = entry.value.activeTabSelector?.takeIf { it.isNotBlank() }
        if (selector.isNullOrBlank()) {
            checkIndex(index + 1)
            return
        }
        val js = """
            (function(){
              return !!document.querySelector('${escapeJsString(selector)}');
            })();
        """.trimIndent()
        webView.post {
            webView.evaluateJavascript(js) { raw ->
                val isActive = raw?.trim()?.trim('"')?.lowercase() == "true"
                if (isActive) {
                    onResult(entry.key)
                } else {
                    checkIndex(index + 1)
                }
            }
        }
    }
    checkIndex(0)
}

fun setWebInputValueBySelector(webView: WebView, selector: String, value: String) {
    setInputValueBySelector(webView, selector, value)
}

fun fillBarcodeUsingTemplate(
    web: WebView,
    rawBarcode: String,
    action: ScanAction?,
    config: DeviceConfig,
    scope: CoroutineScope,
    isQr: Boolean
) {


    val cleanBarcode = rawBarcode.trim()
    val parcel = buildParcelFromBarcode(cleanBarcode)

    if (action == null) {
        setActiveElementValue(web, cleanBarcode)
        return
    }


    fun valueForKey(key: String): String {
        return when (key.lowercase()) {
            "tuid" -> parcel.tuid ?: cleanBarcode
            "trackingno", "tracking_no" -> parcel.trackingNo ?: cleanBarcode
            "carriername", "carrier_name" -> parcel.localCarrierName ?: ""
            "sendername", "carrier_code" -> parcel.localCarrierName ?: ""
            else -> cleanBarcode
        }
    }

    fun idToSelector(id: String) = "#${escapeJsSelector(id)}"
    fun nameToSelector(name: String) = "[name=\"${escapeJsSelector(name)}\"]"

    when (action.action) {
        "fill_field" -> {
            val ids = (action.fieldIds ?: emptyList()).ifEmpty {
                action.fieldId?.let { listOf(it) } ?: emptyList()
            }
            val names = (action.fieldNames ?: emptyList()).ifEmpty {
                action.fieldName?.let { listOf(it) } ?: emptyList()
            }

            if (ids.isEmpty() && names.isEmpty()) {
                setActiveElementValue(web, cleanBarcode)
                return
            }

            // ids
            for (id in ids) {
                val v = valueForKey(id)
                //setWebInputValueBySelector(web, idToSelector(id), v)
                setInputValueBySelector(web, idToSelector(id), v)
            }

            // names
            for (name in names) {
                val v = valueForKey(name)
                setWebInputValueBySelector(web, nameToSelector(name), v)
            }
        }

        "api_check" -> {
            val endpoint = action.endpoint ?: return
            callApiCheck(
                scope = scope,
                cfg = config,
                endpoint = endpoint,
                rawValue = cleanBarcode,
                payloadKey = if (isQr) "qr" else "barcode"
            )
        }

        "web_callback" -> {
            val callbackName = action.callback
            if (callbackName.isNullOrBlank()) {
                println("### web_callback action: callback name is missing")
                return
            }

            println("### Calling web callback: $callbackName with value: $cleanBarcode")
            callWebCallback(web, callbackName, cleanBarcode)
        }
    }
}


fun handleWarehouseMoveScanResult(
    config: DeviceConfig,
    scope: CoroutineScope,
    webView: WebView?,
    scanResult: BarcodeScanResult,
    action: ScanAction?,
    contextKey: String,
    contextConfig: ScanContextConfig,
    onScannerCellUpdate: (String?, String?) -> Unit,
    onBatchCellUpdate: (String?, String?) -> Unit
) {
    val targetWebView = webView ?: return
    val resolvedAction = action ?: return
    when (resolvedAction.action) {
        "fill_field" -> {
            val selector = resolvedAction.fieldId?.let { "#$it" }
                ?: resolvedAction.fieldName?.let { "[name=\"${escapeJsSelector(it)}\"]" }
                ?: return
            val cleanValue = sanitizeBarcodeInput(scanResult.rawValue)
            setInputValueBySelector(targetWebView, selector, cleanValue)
        }
        "api_check" -> {
            val endpoint = resolvedAction.endpoint ?: return
            scope.launch {
                val result = qrCheckWithSession(cfg = config, endpoint = endpoint, qrRaw = scanResult.rawValue)
                if (!result.ok) return@launch
                val cellId = result.cellId ?: return@launch
                val cellCode = result.cellCode
                val selectId = resolvedAction.applyToSelectId
                    ?: contextConfig.qr?.applyToSelectId
                if (contextKey == "batch" || selectId != null) {
                    onBatchCellUpdate(cellId, cellCode)
                    selectId?.let { setSelectValueById(targetWebView, it, cellId) }
                } else {
                    onScannerCellUpdate(cellId, cellCode)
                }
            }
        }
        "web_callback" -> {
            val callbackName = resolvedAction.callback
            if (callbackName.isNullOrBlank()) {
                println("### web_callback action: callback name is missing")
                return
            }

            println("### Calling web callback: $callbackName with value: ${scanResult.rawValue}")
            callWebCallback(targetWebView, callbackName, scanResult.rawValue)
        }
    }
}


/**
 * Вызывает JavaScript callback функцию на веб-странице.
 *
 * @param webView WebView для выполнения JavaScript
 * @param functionName Имя функции в window объекте
 * @param value Значение для передачи в функцию (результат сканирования)
 */
fun callWebCallback(webView: WebView, functionName: String, value: String) {
    if (handleNativeWebCallback(webView, functionName, value)) return

    val escapedFunctionName = escapeJsString(functionName)
    val escapedValue = escapeJsString(value)

    val js = """
        (function(){
          if (typeof window['$escapedFunctionName'] === 'function') {
            try {
              var result = window['$escapedFunctionName']('$escapedValue');
              console.log('✓ Callback $escapedFunctionName returned:', result);
              return result;
            } catch(e) {
              console.error('✗ Error in callback $escapedFunctionName:', e);
              //return false;
              return 'ERR:' + e;
            }
          } else {
            console.error('✗ Callback function $escapedFunctionName not found in window');
            //return false;
            return 'NOFN:' + '${'$'}escapedFunctionName';
          }
        })();
    """.trimIndent()

    webView.post {
        webView.evaluateJavascript(js) { result ->
            println("### callWebCallback($functionName, $value) -> $result")
        }
    }
}


fun handleNativeWebCallback(webView: WebView, functionName: String, value: String): Boolean {
    return when (functionName) {
        "setCellFromQR" -> {
            setWarehouseMoveCellFromQrInWebView(webView, value)
            true
        }
        "setToolsUserFromQR" -> {
            setToolsUserFromQrInWebView(webView, value)
            true
        }
        "setToolsCellFromQR" -> {
            setToolsCellFromQrInWebView(webView, value)
            true
        }
        else -> false
    }
}

/**
 * Вызывает JavaScript callback функцию на веб-странице.
 *
 * @param webView WebView для выполнения JavaScript
 * @param functionName Имя функции в window объекте
 * @param value Значение для передачи в функцию (результат сканирования)
 */

fun requestStandMeasurementInWebView(webView: WebView) {
    val js = "if (window.requestStandMeasurement) { window.requestStandMeasurement(); }"
    webView.post { webView.evaluateJavascript(js, null) }
}

fun captureWarehouseItemStockPhotoInWebView(webView: WebView, photoType: String) {
    val normalized = if (photoType.equals("label", ignoreCase = true)) "label" else "box"
    val escapedType = escapeJsString(normalized)
    val js = """
        (function(){
          try {
            if (window.OCRScanner && typeof window.OCRScanner.captureAndUploadWarehouseItemStockPhoto === 'function') {
              window.OCRScanner.captureAndUploadWarehouseItemStockPhoto('$escapedType');
              return;
            }
            var btnId = '$escapedType' === 'label' ? 'warehouseStockTakeLabelPhotoBtn' : 'warehouseStockTakeBoxPhotoBtn';
            var btn = document.getElementById(btnId);
            if (btn && typeof btn.click === 'function') {
              btn.click();
            }
          } catch (e) {}
        })();
    """.trimIndent()
    webView.post { webView.evaluateJavascript(js, null) }
}

fun withStandDeviceSelected(webView: WebView, onResult: (Boolean) -> Unit) {
    val js = """
        (function(){
          var el = document.getElementById('standDevice');
          return !!(el && el.value);
        })();
    """.trimIndent()
    webView.post {
        webView.evaluateJavascript(js) { raw ->
            val normalized = raw?.trim()?.trim('"')?.lowercase()
            onResult(normalized == "true")
        }
    }
}

fun fillParcelFormInWebView(
    webView: WebView,
    data: OcrParcelData,
    config: ScanTaskConfig? = null,
    includeCarrierFields: Boolean = true
) {
    println("### fillParcelFormInWebView() data = $data")

    fun esc(str: String): String =
        str.replace("\\", "\\\\")
            .replace("'", "\\'")
            .replace("\n", " ")
            .replace("\r", " ")

    val forwarderCode = data.receiverForwarderCode?.trim().takeUnless { it.isNullOrEmpty() }  // CAMEX/KOLLI/...
    val forwarderName = data.receiverCompany?.trim().takeUnless { it.isNullOrEmpty() }       // Camex/KoliExpress/...
    val cellCodeRaw      = data.receiverCellCode?.trim().takeUnless { it.isNullOrEmpty() }      // A176903
    val forwarderCodeForSelect = forwarderCode
        ?: forwarderName?.let { detectForwarderByText(it) }
        ?: cellCodeRaw?.let { detectForwarderByCellCode(it) }
    val forwarderDisplayName = forwarderCodeForSelect?.let { forwarderCompanyByCode(it) } ?: forwarderName
    val receiverName  = data.receiverName?.trim().takeUnless { it.isNullOrEmpty() }          // SEVDA Farzaliyeva


    val countryForSelect = normalizeCountryForSelect(data.receiverCountryCode)               // AZ/GE/KG/DE

    val cellCodeFromConfig = if (cellCodeRaw == null && forwarderCodeForSelect != null) {
        val defaults = config?.cellNullDefaultForward
        val countryCandidates = listOfNotNull(
            data.receiverCountryCode?.trim()?.uppercase(),
            countryForSelect?.uppercase()
        ).distinct()
        val keys = mutableListOf<String>()
        val fwKey = forwarderCodeForSelect.uppercase()
        for (country in countryCandidates) {
            keys += "${fwKey}_${country}"
        }
        keys += fwKey
        keys.firstNotNullOfOrNull { defaults?.get(it) }
    } else null
    val cellCode = cellCodeRaw ?: cellCodeFromConfig


    val localCarrier = data.localCarrierName?.trim()?.takeIf { it.isNotEmpty() }       // DHL/GLS/...
    val trackingForForm = data.localTrackingNo?.trim()?.takeIf { it.isNotEmpty() }
            ?: data.trackingNo?.trim()?.takeIf { isProbableTrackingNo(it) }
            ?: data.tuid?.trim()?.takeIf { it.isNotEmpty() }

    // подпись рядом с TUID: показываем именно форвард/страну (не локального перевозчика)
    val carrierInfo = buildString {
        if (!forwarderCodeForSelect.isNullOrBlank()) append(forwarderCodeForSelect)
        if (!forwarderDisplayName.isNullOrBlank()) {
            if (isNotEmpty()) append(" / ")
            append(forwarderDisplayName)
        }
        if (!countryForSelect.isNullOrBlank()) {
            if (isNotEmpty()) append(" / ")
            append(countryForSelect)
        }
    }

    val js = buildString {
        append("(function(){")
        append(
            """
            function findField(id,name){
              var e = id ? document.getElementById(id) : null;
              if(!e && name){
                e = document.querySelector('[name="'+name+'"]');
              }
              return e;
            }
            function setVal(id,name,v){
              var e = findField(id,name);
              if(e){
                e.value=v;
                e.dispatchEvent(new Event('input',{bubbles:true}));
                e.dispatchEvent(new Event('change',{bubbles:true}));
              }
            }
                                    function setValIfEmpty(id,name,v){
              var e = findField(id,name);
              if(e){
                var isEmpty = !e.value || e.value.trim()==='';
                if(!isEmpty) return;
                e.value=v;
                e.dispatchEvent(new Event('input',{bubbles:true}));
                e.dispatchEvent(new Event('change',{bubbles:true}));
              }
            }
            function setSelectVal(id,name,v){
              var e = findField(id,name);
              if(e){
                e.value=v;
                e.dispatchEvent(new Event('change',{bubbles:true}));
              }
            }
            function setText(id,text){
              var e=document.getElementById(id);
              if(e){ e.textContent=text; }
            }
            """.trimIndent()
        )

            // писать в tuid/trackingNo только если это реально похоже на трек
// 1) TUID — только если нашли
        //data.tuid?.trim()?.takeIf { it.isNotEmpty() }?.let {
        //    append("setValById('tuid','${esc(it)}');")
        //}
        val tuidForForm = data.tuid?.trim()?.takeIf { it.isNotEmpty() } ?: trackingForForm
        tuidForForm?.let { append("setValIfEmpty('tuid','tuid','${esc(it)}');") }
        // 2) trackingNo — DHL/UPS/etc (если нашли), иначе обычный trackingNo (если похож)
        val trackingFieldValue = trackingForForm ?: tuidForForm
        trackingFieldValue?.let {
            append("setValIfEmpty('trackingNo','tracking_no','${esc(it)}');")
        }

        // 2) carrierName (локальный перевозчик: DHL/GLS/HERMES/UPS/AMAZON)
        // carrierName — можно отдельно
        if (includeCarrierFields) {
            localCarrier?.let {
                val v = esc(it)
                append("setSelectVal('carrierName','carrier_name','$v');")
            }
        }

        // 3) receiverCountry (по назначению) — строго ISO2 из select
        countryForSelect?.let {
            //append("setSelectVal('receiverCountry','${esc(it)}');")
            append("setSelectVal('receiverCountry','receiver_country_code','${esc(it)}');")
        }

        // 4) receiverCompany — ТОЛЬКО название компании форварда
        // 5) carrierCode (id=carrierCode) — ТОЛЬКО код форварда + селект форварда
        forwarderCodeForSelect?.let {
            val v = esc(it)
            append("setSelectVal('receiverCompany','receiver_company','$v');")
            append("setVal('carrierCode','sender_code','$v');")
        } ?: forwarderCode?.let {
            append("setVal('carrierCode','sender_code','${esc(it)}');")
        }

        // 6) receiverName — ТОЛЬКО получатель
        receiverName?.let {
            append("setVal('receiverName','receiver_name','${esc(it)}');")
        }

        // 7) receiverAddress — ТОЛЬКО ячейка
        cellCode?.let {
            append("setVal('receiverAddress','receiver_address','${esc(it)}');")
        }

        // 8) подпись рядом с TUID
        if (carrierInfo.isNotBlank()) {
            append("setText('ocrCarrierInfo',' (${esc(carrierInfo)})');")
        } else {
            append("setText('ocrCarrierInfo','');")
        }

        // 9) вес/габариты — НЕ трогаем, если null (и НЕ пишем нули)
        data.weightKg?.let { append("setVal('weightKg','weight_kg','${it}');") }
        data.sizeL?.let   { append("setVal('sizeL','size_l_cm','${it}');") }
        data.sizeW?.let   { append("setVal('sizeW','size_w_cm','${it}');") }
        data.sizeH?.let   { append("setVal('sizeH','size_h_cm','${it}');") }

        append("})();")
    }

    webView.post { webView.evaluateJavascript(js, null) }
}


fun buildParcelFromBarcode(raw: String, sanitizeInput: Boolean = true): OcrParcelData {
    val clean = if (sanitizeInput) sanitizeBarcodeInput(raw) else raw
    val carrier = detectLocalCarrierName(clean)
    return OcrParcelData(
        tuid = clean,
        trackingNo = clean,
        localCarrierName = carrier
    )
}


fun normalizeTrackingScanResult(scanResult: BarcodeScanResult): BarcodeScanResult {
    if (!scanResult.isQr) return scanResult

    val trackingCandidate = extractTrackingFromQrPayload(scanResult.rawValue) ?: return scanResult
    return scanResult.copy(
        rawValue = trackingCandidate,
        isQr = false
    )
}

fun extractTrackingFromQrPayload(raw: String): String? {
    val trimmed = raw.trim()
    if (trimmed.isEmpty()) return null

    val queryCandidate = runCatching {
        val uri = Uri.parse(trimmed)
        val keys = listOf("tracking", "tracking_no", "trackingno", "track", "tuid", "barcode", "awb")
        keys.asSequence()
            .mapNotNull { key -> uri.getQueryParameter(key)?.trim() }
            .firstOrNull { it.isNotEmpty() }
    }.getOrNull()

    val tokens = buildList {
        queryCandidate?.let { add(it) }
        add(trimmed)
        addAll(trimmed.split(Regex("""[\s\n\r\t;|,]+""")))
        addAll(trimmed.split(Regex("[?&=:/]+")))
    }

    val best = tokens
        .asSequence()
        .map { sanitizeBarcodeInput(it) }
        .filter { it.length >= 8 }
        .maxByOrNull { it.length }

    return best?.takeIf { it.any(Char::isDigit) }
}


fun sanitizeBarcodeInput(raw: String): String {
    var s = raw.trim()

    // Отбрасываем префиксы вида "]C1" / "[C1" / "]E0" (символика штрихкода)
    val prefix = Regex("""^[\]\[][A-Za-z]\d""")
    prefix.find(s)?.let { match ->
        s = s.removePrefix(match.value)
    }

    // чистим неалфавитно-цифровые символы по краям
    s = s.trim { !it.isLetterOrDigit() }

    // выбираем самую длинную подходящую алфавитно-цифровую последовательность (8+)
    val best = Regex("[A-Za-z0-9]{8,}").findAll(s).maxByOrNull { it.value.length }?.value

    return (best ?: s).trim()
}


fun normalizeScanRawValue(raw: String): String {
    val trimmed = raw.trim()
    if (trimmed.isEmpty()) return trimmed

    val hasSymbologyPrefix = Regex("""^[\]\[][A-Za-z]\d""").containsMatchIn(trimmed)
    val hasGs1Ai = trimmed.contains("(00)")

    return if (hasSymbologyPrefix || hasGs1Ai) {
        sanitizeBarcodeInput(trimmed)
    } else {
        trimmed
    }
}

fun detectForwarderByCellCode(cellRaw: String): String? {
    val cell = cellRaw.trim().uppercase()

    return when {
        Regex("^(KL|KI)\\d+$").matches(cell) -> "KOLLI"
        Regex("^C\\d+$").matches(cell) || Regex("^S\\d+$").matches(cell) || Regex("^OP\\d+$").matches(cell) -> "COLIBRI"
        Regex("^AS\\d+$").matches(cell) -> "ASER"
        Regex("^PL\\d+$").matches(cell) -> "POSTLINK"
        Regex("^A\\d+$").matches(cell) -> "CAMEX"
        Regex("^FX\\d+$").matches(cell) -> "KARGOFLEX"
        Regex("^B\\d+$").matches(cell) || Regex("^K\\d+$").matches(cell) -> "CAMARATC"
        else -> null
    }
}

fun forwarderCompanyByCode(code: String): String = when (code) {
    "COLIBRI"   -> "Colibri Express"
    "KOLLI"     -> "KoliExpress"
    "ASER"      -> "ASER Express"
    "CAMEX"     -> "Camex"
    "KARGOFLEX" -> "KargoFlex"
    "CAMARATC"  -> "Camaratc"
    "POSTLINK"  -> "Postlink"
    else -> code
}

fun defaultCountryIso2ByForwarder(code: String, fullText: String): String? {
    val t = fullText.lowercase()

    return when (code) {
        "COLIBRI","KOLLI","ASER","CAMEX","KARGOFLEX","POSTLINK" -> "AZ"
        "CAMARATC" -> when {
            t.contains("starkenburgstr.10b") || t.contains("starkenburgstr 10b") || t.contains("starkenburgstr10b") -> "GE"
            t.contains("starkenburgstr.10e") || t.contains("starkenburgstr 10e") || t.contains("starkenburgstr10e") -> "KG"
            else -> null
        }
        else -> null
    }
}

fun isProbableTrackingNo(v: String?): Boolean {
    val s = v?.trim()?.replace(" ", "")?.uppercase() ?: return false
    if (s.length < 9) return false
    if (!s.matches(Regex("^[A-Z0-9\\-]+$"))) return false

    val digitCount = s.count { it.isDigit() }
    if (digitCount < 8) return false   // режет “64546HESSEN”

    return true
}
fun parseOcrText(text: String): OcrParcelData {
    // Разбиваем на строки
    val allLines = text.lines()
    val lines = allLines
        .map { it.trim() }
        .filter { it.isNotEmpty() }

    // ===== ТРЕК =====
    // Длинная строка из латиницы/цифр/дефисов
    // ===== ТРЕК =====
// хотя бы 1 цифра, только A-Z0-9-, длина >= 10
    val trackingCandidate = lines.firstOrNull { isProbableTrackingNo(it) }
    // ===== ПОЛУЧАТЕЛЬ ПО МАРКЕРУ "an:" =====
    var receiverName: String? = null
    var receiverAddress: String? = null

    val idxAn = lines.indexOfFirst { line ->
        val low = line.lowercase()
        low.startsWith("an:") || low.startsWith("an ")
    }

    if (idxAn >= 0) {
        val line = lines[idxAn]
        val low = line.lowercase()

        val anPos = low.indexOf("an")
        var after = line.substring(anPos + 2) // после "an"
            .trim()

        if (after.startsWith(":")) {
            after = after.drop(1).trim()
        }

        if (after.isNotBlank()) {
            // "An: Max Mustermann"
            receiverName = after
            receiverAddress = lines.getOrNull(idxAn + 1)
        } else {
            // "An:" на одной строке, имя и адрес ниже
            receiverName = lines.getOrNull(idxAn + 1)
            receiverAddress = lines.getOrNull(idxAn + 2)
        }
    }

    // ===== ВЕС =====
    val weightRegex = Regex("(\\d+[\\.,]\\d*)\\s*(kg|кг)", RegexOption.IGNORE_CASE)
    val weightMatch = weightRegex.find(text)
    val weightKg = weightMatch
        ?.groups?.get(1)?.value
        ?.replace(',', '.')
        ?.toDoubleOrNull()

    // ===== ГАБАРИТЫ =====
    val sizeRegex = Regex(
        "(\\d+(?:[\\.,]\\d*)?)\\s*[xXх]\\s*(\\d+(?:[\\.,]\\d*)?)\\s*[xXх]\\s*(\\d+(?:[\\.,]\\d*)?)"
    )
    val sizeMatch = sizeRegex.find(text)
    val (sizeL, sizeW, sizeH) =
        if (sizeMatch != null) {
            val lStr = sizeMatch.groupValues[1].replace(',', '.')
            val wStr = sizeMatch.groupValues[2].replace(',', '.')
            val hStr = sizeMatch.groupValues[3].replace(',', '.')
            Triple(
                lStr.toDoubleOrNull(),
                wStr.toDoubleOrNull(),
                hStr.toDoubleOrNull()
            )
        } else {
            Triple(null, null, null)
        }

    return OcrParcelData(
        trackingNo            = trackingCandidate,
        receiverCountryCode   = null,   // страну пока не трогаем, это будет через destConfig
        receiverName          = receiverName,
        receiverAddress       = receiverAddress,
        receiverCompany       = null,
        receiverForwarderCode = null,
        receiverCellCode      = null,
        senderName            = null,
        weightKg              = weightKg,
        sizeL                 = sizeL,
        sizeW                 = sizeW,
        sizeH                 = sizeH
    )
}


fun clearParcelFormInWebView(webView: WebView) {
    val js = """
        (function(){
          function setValById(id,v){
            var e=document.getElementById(id);
            if(e){
              e.value=v;
              e.dispatchEvent(new Event('input',{bubbles:true}));
              e.dispatchEvent(new Event('change',{bubbles:true}));
            }
          }
          function setValByName(name,v){
            var e=document.querySelector('[name="'+name+'"]');
            if(e){
              e.value=v;
              e.dispatchEvent(new Event('input',{bubbles:true}));
              e.dispatchEvent(new Event('change',{bubbles:true}));
            }
          }
          function setText(id,text){
            var e=document.getElementById(id);
            if(e){ e.textContent=text; }
          }

          setValById('tuid','');
          setValById('trackingNo','');

          setValById('carrierName','');
          setValByName('carrierCode','');     // hidden name="carrierCode"

          setValById('receiverCountry','');   // если нет пустого option — визуально может не сброситься
          setValById('receiverName','');
          setValById('receiverAddress','');
          setValById('receiverCompany','');

          setValById('carrierCode','');       // <-- ВАЖНО: форвард CODE именно тут

          setValById('weightKg','');
          setValById('sizeL','');
          setValById('sizeW','');
          setValById('sizeH','');

          setText('ocrCarrierInfo','');
        })();
    """.trimIndent()

    webView.post { webView.evaluateJavascript(js, null) }
}

fun clearAllInWebView(webView: WebView) {

    val jsClearDraft = """
        (function(){
          try {
            if (typeof window.clearItemInDraftForm === 'function') {
              window.clearItemInDraftForm();
              return;
            }
          } catch(e) {}
        })();
    """.trimIndent()
    webView.post { webView.evaluateJavascript(jsClearDraft, null) }

    clearParcelFormInWebView(webView)

    webView.post {
        webView.evaluateJavascript(
            """(function(){
                try {
                    var ids = ["fromCell","toCell","from_cell","to_cell","cellFrom","cellTo"];
                    ids.forEach(function(id){
                        var el = document.getElementById(id);
                        if(!el) return;
                        if(el.tagName === "SELECT") el.selectedIndex = 0;
                        else el.value = "";
                        el.dispatchEvent(new Event('input',{bubbles:true}));
                        el.dispatchEvent(new Event('change',{bubbles:true}));
                    });
                } catch(e) {}
                try {
                    var modalBody = document.querySelector('.modal.show .modal-body');
                    if (modalBody) modalBody.scrollTop = 0;
                    window.scrollTo(0, 0);
                } catch(e) {}
                try {
                    var tuidField = document.getElementById('tuid');
                    if (tuidField) tuidField.focus({preventScroll:true});
                } catch(e) {}
            })();""".trimIndent(),
            null
        )
    }
}

fun clearTrackingAndTuidInWebView(webView: WebView) {
    val js = """
        (function(){
          function setValById(id,v){
            var e=document.getElementById(id);
            if(e){
              e.value=v;
              e.dispatchEvent(new Event('input',{bubbles:true}));
              e.dispatchEvent(new Event('change',{bubbles:true}));
            }
          }

          setValById('tuid','');
          setValById('trackingNo','');
        })();
    """.trimIndent()

    webView.post { webView.evaluateJavascript(js, null) }
}

fun clearMeasurementsInWebView(webView: WebView) {
    val js = """
        (function(){
          function setValById(id,v){
            var e=document.getElementById(id);
            if(e){
              e.value=v;
              e.dispatchEvent(new Event('input',{bubbles:true}));
              e.dispatchEvent(new Event('change',{bubbles:true}));
            }
          }

          setValById('weightKg','');
          setValById('sizeL','');
          setValById('sizeW','');
          setValById('sizeH','');
        })();
    """.trimIndent()

    webView.post { webView.evaluateJavascript(js, null) }
}


fun clearParcelFormExceptTrack(webView: WebView) {
    val js = """
        (function(){
          function setValById(id,v){
            var e=document.getElementById(id);
            if(e){
              e.value=v;
              e.dispatchEvent(new Event('input',{bubbles:true}));
              e.dispatchEvent(new Event('change',{bubbles:true}));
            }
          }
          function setValByName(name,v){
            var e=document.querySelector('[name="'+name+'"]');
            if(e){
              e.value=v;
              e.dispatchEvent(new Event('input',{bubbles:true}));
              e.dispatchEvent(new Event('change',{bubbles:true}));
            }
          }
          function setText(id,text){
            var e=document.getElementById(id);
            if(e){ e.textContent=text; }
          }

          // не трогаем tuid и trackingNo
          setValById('carrierName','');
          setValByName('carrierCode','');     // hidden name="carrierCode"

          setValById('receiverCountry','');
          setValById('receiverName','');
          setValById('receiverAddress','');
          setValById('receiverCompany','');

          setValById('carrierCode','');

          setValById('weightKg','');
          setValById('sizeL','');
          setValById('sizeW','');
          setValById('sizeH','');

          setText('ocrCarrierInfo','');
        })();
    """.trimIndent()

    webView.post { webView.evaluateJavascript(js, null) }
}




fun prepareFormForNextScanInWebView(webView: WebView) {
    val js = """
        (function(){
          function getVal(id){
            var e=document.getElementById(id);
            return e ? (e.value||'').trim() : '';
          }
          function setValById(id,v){
            var e=document.getElementById(id);
            if(e){
              e.value=v;
              e.dispatchEvent(new Event('input',{bubbles:true}));
              e.dispatchEvent(new Event('change',{bubbles:true}));
            }
          }
          function setValByName(name,v){
            var e=document.querySelector('[name="'+name+'"]');
            if(e){
              e.value=v;
              e.dispatchEvent(new Event('input',{bubbles:true}));
              e.dispatchEvent(new Event('change',{bubbles:true}));
            }
          }
          function setText(id,text){
            var e=document.getElementById(id);
            if(e){ e.textContent=text; }
          }
          function scrollToTopAndFocusTuid(){
            try {
              var modalBody = document.querySelector('.modal.show .modal-body');
              if (modalBody) modalBody.scrollTop = 0;
              window.scrollTo(0,0);
            } catch(e) {}
            try {
              var tuidField = document.getElementById('tuid');
              if (tuidField) tuidField.focus({preventScroll:true});
            } catch(e) {}
          }
          var tuid  = getVal('tuid');
          var track = getVal('trackingNo');

          if (tuid || track) {
            var btn = document.querySelector('button.js-core-link[data-core-action="add_new_item_in"]');
            if (btn) btn.click();

            setValById('tuid','');
            setValById('trackingNo','');

            setValById('carrierName','');
            setValByName('carrierCode','');

            setValById('receiverCountry','');
            setValById('receiverName','');
            setValById('receiverAddress','');
            setValById('receiverCompany','');

            setValById('carrierCode',''); // форвард CODE

            setValById('weightKg','');
            setValById('sizeL','');
            setValById('sizeW','');
            setValById('sizeH','');

            setText('ocrCarrierInfo','');
            setTimeout(scrollToTopAndFocusTuid, 50);
          }
        })();
    """.trimIndent()

    webView.post { webView.evaluateJavascript(js, null) }
}


data class BarcodeScanResult(
    val rawValue: String,
    val format: Int,
    val isQr: Boolean
)

@Composable
fun BarcodeScanScreen(
    modifier: Modifier = Modifier,
    config: DeviceConfig,
    onResult: (BarcodeScanResult) -> Unit,
    onCancel: () -> Unit,
    onBindHardwareTrigger: ( (()->Unit)? ) -> Unit,
    taskConfig: ScanTaskConfig?,
    scanMode: String,
    showCameraModeControls: Boolean
) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val prefs = remember {
        context.getSharedPreferences("scanner_camera_prefs", Context.MODE_PRIVATE)
    }
    val zoomPresets = remember { qrZoomPresets() }

    var selectedPresetId by remember {
        mutableStateOf(
            prefs.getString(QR_CAMERA_ZOOM_PRESET_KEY, defaultZoomPresetId(zoomPresets)) ?: defaultZoomPresetId(zoomPresets)
        )
    }

    val selectedPreset = remember(selectedPresetId, zoomPresets) {
        zoomPresets.firstOrNull { it.id == selectedPresetId } ?: zoomPresets.first { it.id == defaultZoomPresetId(zoomPresets) }
    }

    var hasCameraPermission by remember {
        mutableStateOf(
            ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.CAMERA
            ) == PackageManager.PERMISSION_GRANTED
        )
    }

    val permissionLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestPermission(),
        onResult = { granted -> hasCameraPermission = granted }
    )

    LaunchedEffect(Unit) {
        if (!hasCameraPermission) {
            permissionLauncher.launch(Manifest.permission.CAMERA)
        }
    }

    // CameraX
    val previewView = remember {
        PreviewView(context).apply {
            scaleType = PreviewView.ScaleType.FILL_CENTER
        }
    }

    val imageCapture = remember {
        ImageCapture.Builder()
            .setCaptureMode(ImageCapture.CAPTURE_MODE_MINIMIZE_LATENCY)
            .build()
    }

    var errorText by remember { mutableStateOf<String?>(null) }
    var isProcessing by remember { mutableStateOf(false) }
    var camera by remember { mutableStateOf<Camera?>(null) }

    var liveDetectedRaw by remember { mutableStateOf<String?>(null) }
    var liveDetectedFormat by remember { mutableStateOf<Int?>(null) }
    var liveAnalyzerBusy by remember { mutableStateOf(false) }

    val barcodeOptions = remember {
        BarcodeScannerOptions.Builder()
            .setBarcodeFormats(
                Barcode.FORMAT_CODE_128,
                Barcode.FORMAT_CODE_39,
                Barcode.FORMAT_CODE_93,
                Barcode.FORMAT_CODABAR,
                Barcode.FORMAT_EAN_8,
                Barcode.FORMAT_EAN_13,
                Barcode.FORMAT_UPC_A,
                Barcode.FORMAT_UPC_E,
                Barcode.FORMAT_ITF,
                Barcode.FORMAT_QR_CODE,
                Barcode.FORMAT_DATA_MATRIX,
                Barcode.FORMAT_PDF417,
                Barcode.FORMAT_AZTEC
            )
            .build()
    }

    fun captureAndScan() {
        if (isProcessing) return

        errorText = null
        isProcessing = true

        val executor = ContextCompat.getMainExecutor(context)
        imageCapture.takePicture(executor, object : ImageCapture.OnImageCapturedCallback() {
            override fun onCaptureSuccess(image: ImageProxy) {
                try {
                    val mediaImage = image.image
                    if (mediaImage == null) {
                        errorText = "Нет данных изображения"
                        isProcessing = false
                        image.close()
                        return
                    }

                    val inputImage = InputImage.fromMediaImage(
                        mediaImage,
                        image.imageInfo.rotationDegrees
                    )

                    val dataMatrixOnlyOptions = BarcodeScannerOptions.Builder()
                        .setBarcodeFormats(Barcode.FORMAT_DATA_MATRIX)
                        .build()

                    val scanner = BarcodeScanning.getClient(barcodeOptions)
                    val dataMatrixScanner = BarcodeScanning.getClient(dataMatrixOnlyOptions)
                    var fallbackStarted = false

                    fun emitResult(first: Barcode) {
                        val raw = first.rawValue
                        if (raw.isNullOrBlank()) {
                            errorText = "Код не найден"
                            return
                        }

                        val is2D =
                            first.format == Barcode.FORMAT_QR_CODE ||
                                    first.format == Barcode.FORMAT_DATA_MATRIX ||
                                    first.format == Barcode.FORMAT_PDF417 ||
                                    first.format == Barcode.FORMAT_AZTEC

                        onResult(
                            BarcodeScanResult(
                                rawValue = normalizeScanRawValue(raw),
                                format = first.format,
                                isQr = is2D
                            )
                        )
                    }

                    fun tryDataMatrixCenterCropFallback() {
                        if (fallbackStarted) return
                        fallbackStarted = true
                        val width = mediaImage.width
                        val height = mediaImage.height
                        val cropW = (width * 0.72f).toInt()
                        val cropH = (height * 0.72f).toInt()
                        val left = ((width - cropW) / 2).coerceAtLeast(0)
                        val top = ((height - cropH) / 2).coerceAtLeast(0)
                        val right = (left + cropW).coerceAtMost(width)
                        val bottom = (top + cropH).coerceAtMost(height)

                        image.setCropRect(Rect(left, top, right, bottom))
                        val croppedImage = InputImage.fromMediaImage(
                            mediaImage,
                            image.imageInfo.rotationDegrees
                        )

                        dataMatrixScanner.process(croppedImage)
                            .addOnSuccessListener { dmCodes ->
                                val firstDm = dmCodes.firstOrNull()
                                if (firstDm?.rawValue.isNullOrBlank()) {
                                    errorText = "Data Matrix не найден"
                                } else {
                                    emitResult(firstDm!!)
                                }
                            }
                            .addOnFailureListener { e ->
                                errorText = "Ошибка Data Matrix: ${e.message}"
                            }
                            .addOnCompleteListener {
                                isProcessing = false
                                scanner.close()
                                dataMatrixScanner.close()
                                image.close()
                            }
                    }

                    scanner.process(inputImage)
                        .addOnSuccessListener { codes ->
                            val first = codes.firstOrNull()
                            if (first?.rawValue.isNullOrBlank()) {
                                tryDataMatrixCenterCropFallback()
                            } else {
                                emitResult(first!!)
                            }
                        }
                        .addOnFailureListener { e ->
                            errorText = "Ошибка сканера: ${e.message}"
                            tryDataMatrixCenterCropFallback()
                        }
                        .addOnCompleteListener {
                            if (fallbackStarted || !isProcessing) return@addOnCompleteListener
                            isProcessing = false
                            scanner.close()
                            dataMatrixScanner.close()
                            image.close()
                        }
                } catch (e: Exception) {
                    errorText = "Сбой камеры"
                    isProcessing = false
                    image.close()
                }
            }

            override fun onError(exception: ImageCaptureException) {
                errorText = "Ошибка камеры: ${exception.message}"
                isProcessing = false
            }
        })
    }

    DisposableEffect(hasCameraPermission, config.liveScanEnabled, liveDetectedRaw, liveDetectedFormat) {
        if (hasCameraPermission) {
            onBindHardwareTrigger {
                if (config.liveScanEnabled && !liveDetectedRaw.isNullOrBlank()) {
                    val format = liveDetectedFormat ?: Barcode.FORMAT_UNKNOWN
                    val is2D =
                        format == Barcode.FORMAT_QR_CODE ||
                                format == Barcode.FORMAT_DATA_MATRIX ||
                                format == Barcode.FORMAT_PDF417 ||
                                format == Barcode.FORMAT_AZTEC
                    onResult(BarcodeScanResult(normalizeScanRawValue(liveDetectedRaw!!), format, is2D))
                } else {
                    captureAndScan()
                }
            }
        } else {
            onBindHardwareTrigger(null)
        }

        onDispose {
            onBindHardwareTrigger(null)
        }
    }

    LaunchedEffect(hasCameraPermission) {
        if (!hasCameraPermission) return@LaunchedEffect

        val cameraProviderFuture = ProcessCameraProvider.getInstance(context)
        val cameraProvider = cameraProviderFuture.get()

        val preview = Preview.Builder()
            .build()
            .also { it.setSurfaceProvider(previewView.surfaceProvider) }

        val selector = CameraSelector.DEFAULT_BACK_CAMERA

        try {
            cameraProvider.unbindAll()

            if (config.liveScanEnabled) {
                val analysis = ImageAnalysis.Builder()
                    .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                    .build()
                val scanner = BarcodeScanning.getClient(barcodeOptions)
                analysis.setAnalyzer(ContextCompat.getMainExecutor(context)) { imageProxy ->
                    val mediaImage = imageProxy.image
                    if (mediaImage == null || liveAnalyzerBusy) {
                        imageProxy.close(); return@setAnalyzer
                    }
                    liveAnalyzerBusy = true
                    val input = InputImage.fromMediaImage(mediaImage, imageProxy.imageInfo.rotationDegrees)
                    scanner.process(input)
                        .addOnSuccessListener { codes ->
                            val first = codes.firstOrNull { !it.rawValue.isNullOrBlank() }
                            if (first != null) {
                                liveDetectedRaw = first.rawValue?.let(::normalizeScanRawValue)
                                liveDetectedFormat = first.format
                            }
                        }
                        .addOnCompleteListener {
                            liveAnalyzerBusy = false
                            imageProxy.close()
                        }
                }
                camera = cameraProvider.bindToLifecycle(lifecycleOwner, selector, preview, imageCapture, analysis)
            } else {
                camera = cameraProvider.bindToLifecycle(
                    lifecycleOwner,
                    selector,
                    preview,
                    imageCapture
                )
            }
        } catch (e: Exception) {
            errorText = "Не удалось открыть камеру"
        }
    }

    LaunchedEffect(camera, selectedPreset.zoomRatio) {
        val activeCamera = camera ?: return@LaunchedEffect
        val zoomState = activeCamera.cameraInfo.zoomState.value ?: return@LaunchedEffect
        val targetZoom = selectedPreset.zoomRatio.coerceIn(
            zoomState.minZoomRatio,
            zoomState.maxZoomRatio
        )
        activeCamera.cameraControl.setZoomRatio(targetZoom)
    }

    Box(modifier = modifier.fillMaxSize()) {
        if (!hasCameraPermission) {
            Column(
                modifier = Modifier
                    .align(Alignment.Center)
                    .padding(16.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                Text("Нет доступа к камере")
                Spacer(Modifier.height(16.dp))
                OutlinedButton(onClick = onCancel) {
                    Text("Назад")
                }
            }
        } else {
            AndroidView(
                modifier = Modifier.fillMaxSize(),
                factory = { previewView }
            )


            val taskLabel = taskConfig?.ui?.stepLabels?.get(scanMode)
                ?.trim()
                ?.takeIf { it.isNotEmpty() }
                ?: taskConfig?.taskId
                    ?.trim()
                    ?.takeIf { it.isNotEmpty() }
                    ?.replace("_", " ")
            val bannerText = taskLabel ?: "DEFAULT SCAN"
            val bannerColor = if (taskLabel != null) {
                androidx.compose.ui.graphics.Color(0xFF2E7D32)
            } else {
                MaterialTheme.colorScheme.error
            }

            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .fillMaxHeight(0.15f)
                    .align(Alignment.TopCenter),
                contentAlignment = Alignment.Center
            ) {
                Text(
                    text = bannerText,
                    color = bannerColor,
                    style = MaterialTheme.typography.headlineLarge,
                    fontWeight = androidx.compose.ui.text.font.FontWeight.Black
                )
            }

            Column(
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .fillMaxWidth()
                    .padding(16.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                errorText?.let {
                    Text(it, color = MaterialTheme.colorScheme.error)
                    Spacer(Modifier.height(8.dp))
                }

                if (isProcessing) {
                    Text("Сканирование…")

                } else if (config.liveScanEnabled) {
                    Text(liveDetectedRaw?.let { "Live: $it" } ?:
                    "Live: наведи камеру на код",
                        color = androidx.compose.ui.graphics.Color.Yellow,
                        style = MaterialTheme.typography.titleLarge
                    )
                }

                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween
                ) {
                    OutlinedButton(onClick = onCancel, enabled = !isProcessing) {
                        Text("Отмена")
                    }

                    Button(onClick = { captureAndScan() }, enabled = !isProcessing) {
                        Text("BarScann")
                    }
                }
            }

            if (showCameraModeControls) {
                CameraZoomButtonsVertical(
                    modifier = Modifier
                        .align(Alignment.CenterStart)
                        .padding(start = 12.dp),
                    zoomPresets = zoomPresets,
                    selectedPresetId = selectedPreset.id,
                    onSelectPreset = { preset ->
                        selectedPresetId = preset.id
                        prefs.edit().putString(QR_CAMERA_ZOOM_PRESET_KEY, preset.id).apply()
                    }
                )
            }
        }
    }
}

@Composable
fun OcrScanScreen(
    modifier: Modifier = Modifier,
    destConfig: List<DestCountryCfg>,          // ДОБАВЛЕНО
    config: DeviceConfig,
    nameDict: NameDict?,                         // <<< НОВОЕ
    taskConfig: ScanTaskConfig?,
    scanMode: String,
    onResult: (OcrParcelData) -> Unit,
    onCancel: () -> Unit,
    onBindHardwareTrigger: ((() -> Unit)?) -> Unit,
    onBarcodeClick: (() -> Unit)? = null,
    onBpClick: (() -> Unit)? = null,
    showCameraModeControls: Boolean
) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val scope = rememberCoroutineScope()
    val prefs = remember {
        context.getSharedPreferences("scanner_camera_prefs", Context.MODE_PRIVATE)
    }
    val zoomPresets = remember { qrZoomPresets() }

    var selectedPresetId by remember {
        mutableStateOf(
            prefs.getString(QR_CAMERA_ZOOM_PRESET_KEY, defaultZoomPresetId(zoomPresets))
                ?: defaultZoomPresetId(zoomPresets)
        )
    }

    val selectedPreset = remember(selectedPresetId, zoomPresets) {
        zoomPresets.firstOrNull { it.id == selectedPresetId }
            ?: zoomPresets.first { it.id == defaultZoomPresetId(zoomPresets) }
    }

    // ===== Разрешение камеры =====
    var hasCameraPermission by remember {
        mutableStateOf(
            ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.CAMERA
            ) == PackageManager.PERMISSION_GRANTED
        )
    }

    val permissionLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestPermission()
    ) { granted ->
        hasCameraPermission = granted
    }

    LaunchedEffect(Unit) {
        if (!hasCameraPermission) {
            permissionLauncher.launch(Manifest.permission.CAMERA)
        }
    }

    // ===== CameraX: превью + ImageCapture =====
    val previewView = remember {
        PreviewView(context).apply {
            scaleType = PreviewView.ScaleType.FILL_CENTER
        }
    }

    val imageCapture = remember {
        ImageCapture.Builder()
            .setCaptureMode(ImageCapture.CAPTURE_MODE_MINIMIZE_LATENCY)
            .build()
    }

    val executor = remember { Executors.newSingleThreadExecutor() }

    val recognizer = remember {
        TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)
    }

    var isProcessing by remember { mutableStateOf(false) }
    var errorText by remember { mutableStateOf<String?>(null) }
    var camera by remember { mutableStateOf<Camera?>(null) }

    var liveOcrText by remember { mutableStateOf<String?>(null) }
    var liveOcrPreview by remember { mutableStateOf<String?>(null) }
    var liveOcrBusy by remember { mutableStateOf(false) }

    fun processRecognizedText(fullText: String) {
        scope.launch {
            try {
                if (config.useRemoteOcr) {
                    val remote = callRemoteOcrParse(context, config, fullText)
                    if (remote.ok && remote.data != null) {
                        val base = remote.data
                        val lc = detectLocalCarrierName(fullText)
                        val lt = detectLocalTrackingNo(fullText, lc)
                        val tuid = lt ?: base.trackingNo
                        onResult(base.copy(tuid = tuid, localCarrierName = lc, localTrackingNo = lt))
                    } else {
                        val basic = parseOcrText(fullText)
                        val advanced = buildOcrParcelDataFromText(fullText = fullText, trackingNo = basic.trackingNo, destConfig = destConfig, nameDict = nameDict)
                        val merged = basic.copy(
                            receiverCountryCode    = advanced.receiverCountryCode    ?: basic.receiverCountryCode,
                            receiverCompany        = advanced.receiverCompany        ?: basic.receiverCompany,
                            receiverForwarderCode  = advanced.receiverForwarderCode  ?: basic.receiverForwarderCode,
                            receiverCellCode       = advanced.receiverCellCode       ?: basic.receiverCellCode,
                            receiverName           = advanced.receiverName           ?: basic.receiverName
                        )
                        val lc = detectLocalCarrierName(fullText)
                        val lt = detectLocalTrackingNo(fullText, lc)
                        val tuid = detectTuid(fullText)
                        onResult(merged.copy(tuid = tuid, localCarrierName = lc, localTrackingNo = lt))
                        errorText = remote.errorMessage?.let { "Удалённый парсер не сработал: $it (использован локальный)" }
                    }
                } else {
                    val basic = parseOcrText(fullText)
                    val advanced = buildOcrParcelDataFromText(fullText = fullText, trackingNo = basic.trackingNo, destConfig = destConfig, nameDict = nameDict)
                    val merged = basic.copy(
                        receiverCountryCode    = advanced.receiverCountryCode    ?: basic.receiverCountryCode,
                        receiverCompany        = advanced.receiverCompany        ?: basic.receiverCompany,
                        receiverForwarderCode  = advanced.receiverForwarderCode  ?: basic.receiverForwarderCode,
                        receiverCellCode       = advanced.receiverCellCode       ?: basic.receiverCellCode,
                        receiverName           = advanced.receiverName           ?: basic.receiverName
                    )
                    val lc = detectLocalCarrierName(fullText)
                    val lt = detectLocalTrackingNo(fullText, lc)
                    val tuid = detectTuid(fullText)
                    onResult(merged.copy(tuid = tuid, localCarrierName = lc, localTrackingNo = lt))
                }
            } finally {
                isProcessing = false
            }
        }
    }

    fun startCamera() {
        val cameraProviderFuture = ProcessCameraProvider.getInstance(context)
        val cameraProvider = cameraProviderFuture.get()

        val preview = Preview.Builder()
            .build()
            .also { it.setSurfaceProvider(previewView.surfaceProvider) }

        val selector = CameraSelector.DEFAULT_BACK_CAMERA

        try {
            cameraProvider.unbindAll()
            if (config.liveScanEnabled) {
                val analysis = ImageAnalysis.Builder()
                    .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                    .build()
                analysis.setAnalyzer(executor) { imageProxy ->
                    val mediaImage = imageProxy.image
                    if (mediaImage == null || liveOcrBusy || isProcessing) {
                        imageProxy.close(); return@setAnalyzer
                    }
                    liveOcrBusy = true
                    val input = InputImage.fromMediaImage(mediaImage, imageProxy.imageInfo.rotationDegrees)
                    recognizer.process(input)
                        .addOnSuccessListener { result ->
                            val txt = result.text?.trim().orEmpty()
                            if (txt.isNotEmpty()) {
                                liveOcrText = txt
                                liveOcrPreview = buildLiveOcrPreview(
                                    fullText = txt,
                                    destConfig = destConfig,
                                    nameDict = nameDict
                                )
                            } else {
                                liveOcrText = null
                                liveOcrPreview = null
                            }
                        }
                        .addOnCompleteListener {
                            liveOcrBusy = false
                            imageProxy.close()
                        }
                }
                camera = cameraProvider.bindToLifecycle(lifecycleOwner, selector, preview, imageCapture, analysis)
            } else {
                camera = cameraProvider.bindToLifecycle(
                    lifecycleOwner,
                    selector,
                    preview,
                    imageCapture
                )
            }
        } catch (e: Exception) {
            e.printStackTrace()
            errorText = "Ошибка запуска камеры: ${e.message}"
        }
    }

    LaunchedEffect(hasCameraPermission) {
        if (hasCameraPermission) {
            startCamera()
        }
    }

    LaunchedEffect(camera, selectedPreset.zoomRatio) {
        val activeCamera = camera ?: return@LaunchedEffect
        val zoomState = activeCamera.cameraInfo.zoomState.value ?: return@LaunchedEffect
        val targetZoom = selectedPreset.zoomRatio.coerceIn(
            zoomState.minZoomRatio,
            zoomState.maxZoomRatio
        )
        activeCamera.cameraControl.setZoomRatio(targetZoom)
    }

    fun captureAndRecognize() {
        if (!hasCameraPermission || isProcessing) return

        isProcessing = true
        errorText = null

        imageCapture.takePicture(
            executor,
            object : ImageCapture.OnImageCapturedCallback() {
                override fun onCaptureSuccess(imageProxy: ImageProxy) {
                    val mediaImage = imageProxy.image
                    if (mediaImage == null) {
                        imageProxy.close()
                        isProcessing = false
                        return
                    }

                    val inputImage = InputImage.fromMediaImage(
                        mediaImage,
                        imageProxy.imageInfo.rotationDegrees
                    )

                    recognizer
                        .process(inputImage)
                        .addOnSuccessListener { result ->
                            val fullText = result.text ?: ""

                            processRecognizedText(fullText)
                        }
                        .addOnFailureListener { e ->
                            errorText = "Не удалось распознать: ${e.message}"
                            isProcessing = false
                        }
                        .addOnCompleteListener {
                            imageProxy.close()
                        }
                }

                override fun onError(exception: ImageCaptureException) {
                    errorText = "Ошибка камеры: ${exception.message}"
                    isProcessing = false
                }
            }
        )
    }



    // ===== Привязка VOL_DOWN к captureAndRecognize =====
    DisposableEffect(hasCameraPermission, config.liveScanEnabled, liveOcrText) {
        if (hasCameraPermission) {
            onBindHardwareTrigger {
                if (config.liveScanEnabled && !liveOcrText.isNullOrBlank() && !isProcessing) {
                    isProcessing = true
                    processRecognizedText(liveOcrText!!)
                } else {
                    captureAndRecognize()
                }
            }
        } else {
            onBindHardwareTrigger(null)
        }

        onDispose {
            onBindHardwareTrigger(null)
        }
    }

    // ===== UI =====
    Box(
        modifier = modifier.fillMaxSize()
    ) {
        if (!hasCameraPermission) {
            Column(
                modifier = Modifier
                    .align(Alignment.Center)
                    .padding(16.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                Text("Нет доступа к камере")
                Spacer(Modifier.height(16.dp))
                OutlinedButton(onClick = onCancel) {
                    Text("Назад")
                }
            }
        } else {
            // Превью камеры
            AndroidView(
                modifier = Modifier.fillMaxSize(),
                factory = { previewView }
            )
            val taskLabel = taskConfig?.ui?.stepLabels?.get(scanMode)
                ?.trim()
                ?.takeIf { it.isNotEmpty() }
                ?: taskConfig?.taskId
                    ?.trim()
                    ?.takeIf { it.isNotEmpty() }
                    ?.replace("_", " ")
            val bannerText = taskLabel ?: "DEFAULT SCAN"
            val bannerColor = if (taskLabel != null) {
                androidx.compose.ui.graphics.Color(0xFF2E7D32)
            } else {
                MaterialTheme.colorScheme.error
            }

            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .fillMaxHeight(0.15f)
                    .align(Alignment.TopCenter),
                contentAlignment = Alignment.Center
            ) {
                Text(
                    text = bannerText,
                    color = bannerColor,
                    style = MaterialTheme.typography.headlineLarge,
                    fontWeight = androidx.compose.ui.text.font.FontWeight.Black
                )
            }

            // Нижняя панель с кнопками
            Column(
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .fillMaxWidth()
                    .padding(16.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                if (errorText != null) {
                    Text(
                        text = errorText!!,
                        color = MaterialTheme.colorScheme.error
                    )
                    Spacer(Modifier.height(8.dp))
                }

                if (isProcessing) {
                    Text("Обработка снимка…")
                    Spacer(Modifier.height(8.dp))
                } else {
                    if (config.liveScanEnabled) {
                        Text(liveOcrPreview?.let { "Live OCR поля:\n$it" }
                            ?: "Live OCR: наведи камеру на текст",
                            color = androidx.compose.ui.graphics.Color.Yellow,
                            style = MaterialTheme.typography.titleLarge
                        )
                    } else {
                        Text("Нажми громкость вниз или кнопку ниже для скана")
                    }
                    Spacer(Modifier.height(8.dp))
                }

                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween
                ) {
                    OutlinedButton(
                        onClick = onCancel,
                        enabled = !isProcessing
                    ) {
                        Text("Отмена")
                    }

                    Button(
                        onClick = { captureAndRecognize() },
                        enabled = !isProcessing
                    ) {
                        Text("OcrScann")
                    }
                }
            }
            if (showCameraModeControls) {
                CameraZoomButtonsVertical(
                    modifier = Modifier
                        .align(Alignment.CenterStart)
                        .padding(start = 12.dp),
                    zoomPresets = zoomPresets,
                    selectedPresetId = selectedPreset.id,
                    onSelectPreset = { preset ->
                        selectedPresetId = preset.id
                        prefs.edit().putString(QR_CAMERA_ZOOM_PRESET_KEY, preset.id).apply()

                    }
                )
            }
        }
    }
}

fun parseDestConfigJson(json: String?): List<DestCountryCfg> {
    val s = json?.trim()
    if (s.isNullOrEmpty() || s.equals("null", true) || s.equals("undefined", true)) {
        return emptyList()
    }

    return try {
        val arr = org.json.JSONArray(s)
        val result = mutableListOf<DestCountryCfg>()

        for (i in 0 until arr.length()) {
            val o = arr.getJSONObject(i)

            val aliases = o.optJSONArray("aliases")?.let { ja ->
                (0 until ja.length()).map { ja.optString(it) }
            } ?: emptyList()

            val fwArr = o.optJSONArray("forwarders")
            val forwarders = mutableListOf<DestForwarder>()
            if (fwArr != null) {
                for (j in 0 until fwArr.length()) {
                    val f = fwArr.getJSONObject(j)
                    val fAliases = f.optJSONArray("aliases")?.let { ja ->
                        (0 until ja.length()).map { ja.optString(it) }
                    } ?: emptyList()

                    forwarders += DestForwarder(
                        code = f.optString("code", ""),
                        name = f.optString("name", ""),
                        aliases = fAliases
                    )
                }
            }

            result += DestCountryCfg(
                id         = o.optInt("id"),
                code_iso2  = o.optString("code_iso2", ""),
                code_iso3  = o.optString("code_iso3", ""),
                name_en    = o.optString("name_en", ""),
                name_local = o.optString("name_local", ""),
                aliases    = aliases,
                forwarders = forwarders
            )
        }

        result
    } catch (e: Exception) {
        e.printStackTrace()
        emptyList()
    }
}


fun detectDestCountryAndForwarder(
    textRaw: String,
    countries: List<DestCountryCfg>
): DetectedDest {
    val text = textRaw.lowercase()

    // 1. Страна: ищем, у кого больше совпавших алиасов
    var bestCountry: DestCountryCfg? = null
    var bestCountryScore = 0

    for (c in countries) {
        var score = 0
        for (alias in c.aliases) {
            val a = alias.lowercase()
            if (a.isNotBlank() && text.contains(a)) {
                score++
            }
        }
        if (score > bestCountryScore) {
            bestCountryScore = score
            bestCountry = c
        }
    }

    // 2. Форвардер: внутри выбранной страны
    var bestForwarder: DestForwarder? = null
    var bestFwScore = 0

    val country = bestCountry
    if (country != null) {
        for (fw in country.forwarders) {
            var score = 0
            for (alias in fw.aliases) {
                val a = alias.lowercase()
                if (a.isNotBlank() && text.contains(a)) {
                    score++
                }
            }
            if (score > bestFwScore) {
                bestFwScore = score
                bestForwarder = fw
            }
        }
    }

    return DetectedDest(
        countryCode   = country?.code_iso2,
        countryName   = country?.name_en,
        forwarderCode = bestForwarder?.code,
        forwarderName = bestForwarder?.name
    )
}

fun buildOcrParcelDataFromText(
    fullText: String,
    trackingNo: String?,
    destConfig: List<DestCountryCfg>,
    nameDict: NameDict?
): OcrParcelData {

    val detected = detectDestCountryAndForwarder(fullText, destConfig)
    val cellCode = detectCellCode(fullText)

    var forwarderCode = detected.forwarderCode
    var forwarderName = detected.forwarderName
    var countryIso2   = detected.countryCode

    // 1) если не нашли по destConfig — пробуем по ячейке
    if (forwarderCode == null && cellCode != null) {
        detectForwarderByCellCode(cellCode)?.let { code ->
            forwarderCode = code
            forwarderName = forwarderCompanyByCode(code)
        }
    }

    // 2) если всё ещё не нашли — пробуем по тексту (COLIBRI/KOLI/…)
    if (forwarderCode == null) {
        detectForwarderByText(fullText)?.let { code ->
            forwarderCode = code
            forwarderName = forwarderCompanyByCode(code)
        }
    }

    // 3) страна: форвардер важнее “DE/Germany/Hessen” в тексте
    if (forwarderCode != null) {
        // форвардеры, которые всегда AZ
        if (forwarderCode in setOf("COLIBRI","KOLLI","ASER","CAMEX","KARGOFLEX","POSTLINK")) {
            countryIso2 = "AZ"
        } else if (forwarderCode == "CAMARATC") {
            // CAMARATC: GE/KG по адресу, если получилось распознать
            defaultCountryIso2ByForwarder("CAMARATC", fullText)?.let { countryIso2 = it }
            // если не получилось — лучше оставить null, чем DE
            if (countryIso2 == "DE") countryIso2 = null
        } else {
            // общий fallback
            if (countryIso2 == null || countryIso2 == "DE") {
                defaultCountryIso2ByForwarder(forwarderCode!!, fullText)?.let { countryIso2 = it }
            }
        }
    }

    val clientName = detectClientName(
        text = fullText,
        forwarderCode = forwarderCode,
        cellCode = cellCode,
        nameDict = nameDict
    )

    return OcrParcelData(
        trackingNo            = trackingNo,
        receiverCountryCode   = countryIso2,
        receiverCompany       = forwarderName,
        receiverForwarderCode = forwarderCode,
        receiverCellCode      = cellCode,
        receiverName          = clientName,
        receiverAddress       = null,
        weightKg              = null,
        sizeL                 = null,
        sizeW                 = null,
        sizeH                 = null
    )
}
fun sanitizeCellCode(raw: String): String {
    val upper = raw.uppercase()

    // 1) Частый OCR-ошибочный вариант Postlink: PL"O"xxxx -> PL0xxxx
    Regex("^([A-Z]{2})O(\\d{3,8})$").matchEntire(upper)?.let { m ->
        return m.groupValues[1] + "0" + m.groupValues[2]
    }

    // 2) Основной случай: буквы + цифры (с возможными O в цифровой части)
    Regex("^([A-Z]{1,3})([0-9O]{3,8})$").matchEntire(upper)?.let { m ->
        val prefix = m.groupValues[1]
        val numeric = m.groupValues[2].replace('O', '0')
        return prefix + numeric
    }

    // 3) Без распознавания шаблона просто возвращаем верхний регистр
    return upper
}

private fun cellPrefixByForwarderCode(code: String?): String? {
    return when (code?.uppercase()) {
        "COLIBRI" -> "C"
        "KOLLI" -> "KL"
        "ASER" -> "AS"
        "CAMEX" -> "A"
        "KARGOFLEX" -> "FX"
        "POSTLINK" -> "PL"
        "CAMARATC" -> "B"
        else -> null
    }
}

/**
 * Пытаемся вытащить код ячейки вида A66050 / AS228905 / C163361.
 * Дополнительно поддерживаем формат с #, например: "Colibri #185978" -> "C185978".
 **/
fun detectCellCode(text: String): String? {

    val normalized = text.replace("\n", " ")

    // 1) Прямой формат: буквы+цифры
    Regex("\\b[A-Z]{1,3}\\d{3,8}\\b").find(normalized)?.value?.let {
        return sanitizeCellCode(it)
    }

    // 2) Формат с '#': пробуем восстановить префикс по форвардеру в строке
    val hashDigits = Regex("#\\s*(\\d{3,8})")
    val forwarderByAlias = listOf(
        Regex("\\bcolibri\\b", RegexOption.IGNORE_CASE) to "COLIBRI",
        Regex("\\b(koli|koliexp|koliexpress|koli\\s*express)\\b", RegexOption.IGNORE_CASE) to "KOLLI",
        Regex("\\baser\\b", RegexOption.IGNORE_CASE) to "ASER",
        Regex("\\bcamex\\b", RegexOption.IGNORE_CASE) to "CAMEX",
        Regex("\\bkargoflex\\b", RegexOption.IGNORE_CASE) to "KARGOFLEX",
        Regex("\\bpostlink\\b", RegexOption.IGNORE_CASE) to "POSTLINK",
        Regex("\\bcamaratc\\b", RegexOption.IGNORE_CASE) to "CAMARATC"
    )

    text.lines().forEach { line ->
        val digits = hashDigits.find(line)?.groupValues?.getOrNull(1) ?: return@forEach
        val forwarderCode = forwarderByAlias.firstOrNull { (re, _) -> re.containsMatchIn(line) }?.second
        val prefix = cellPrefixByForwarderCode(forwarderCode) ?: return@forEach
        return sanitizeCellCode(prefix + digits)
    }

    // 3) Если форвардер встречается где-то в тексте — fallback для '#123456'
    val digits = hashDigits.find(normalized)?.groupValues?.getOrNull(1)
    if (digits != null) {
        val code = detectForwarderByText(text)
        val prefix = cellPrefixByForwarderCode(code)
        if (prefix != null) {
            return sanitizeCellCode(prefix + digits)
        }
    }

    return null
}

/**
 * Пытаемся вытащить ФИО конечного клиента.
 * Черновой вариант: первая строка без цифр, в которой >=2 слов с заглавной буквы.
 */
fun looksLikePersonName(lineRaw: String, dict: NameDict?): Boolean {
    val line = lineRaw.trim()
    if (line.isEmpty()) return false
    if (line.length > 45) return false

    val low = line.lowercase()

    // словарь: точные совпадения
    if (dict != null) {
        if (dict.exactBad.contains(low)) return false
        for (sub in dict.substrBad) {
            if (sub.isNotEmpty() && low.contains(sub)) {
                return false
            }
        }
    }

    // хотя бы одна буква
    if (!Regex("[A-Za-zÄÖÜäöüß]").containsMatchIn(line)) return false
    // не хотим цифр
    if (Regex("\\d").containsMatchIn(line)) return false

    val words = line.split(Regex("\\s+")).filter { it.isNotBlank() }
    if (words.size < 2) return false

    val capitalWords = words.count { w ->
        val c = w.firstOrNull()
        c != null && c.isLetter() && c.isUpperCase()
    }
    if (capitalWords == 0) return false

    return true
}

fun cleanNameLine(
    lineRaw: String,
    forwarderCode: String?,
    cellCode: String?
): String? {
    var line = lineRaw.trim()
    if (line.isEmpty()) return null

    // служебные префиксы вначале
    line = Regex(
        pattern = "^(to|an|von|from|empf[aäå]nger(?:in)?|addressee|receiver|contact|billing\\s+no)\\s*:?\\s*",
        options = setOf(RegexOption.IGNORE_CASE)
    ).replace(line, "")
// либо вообще без options, если регистр не критичен

    // спец-слова по форвардеру
    val patterns = mutableListOf<Regex>()
    // общие форвардер-бренды (работают даже когда forwarderCode == null)
    patterns += Regex("\\bCOLIBR\\p{L}*(?:\\s+EXP\\p{L}*)?\\b", RegexOption.IGNORE_CASE)
    patterns += Regex("\\bKOLI\\p{L}*(?:\\s*EXP\\p{L}*)?\\b", RegexOption.IGNORE_CASE)
    patterns += Regex("\\b(CAMEX|ASER|POSTLINK|KARGOFLEX|CAMARATC)\\p{L}*\\b", RegexOption.IGNORE_CASE)

    when (forwarderCode) {
        "COLIBRI" -> {
            patterns += Regex("\\bCOLIBR\\p{L}*\\s+EXP\\p{L}*\\b", RegexOption.IGNORE_CASE)
            patterns += Regex("\\bCOLIBR\\p{L}*\\b", RegexOption.IGNORE_CASE)
        }
        "KOLLI" -> {
            patterns += Regex("\\bKOLI\\p{L}*\\s*EXP\\p{L}*\\b", RegexOption.IGNORE_CASE)
            patterns += Regex("\\bKOLI\\p{L}*\\b", RegexOption.IGNORE_CASE)
        }
        "ASER" -> {
            patterns += Regex("\\bASER\\p{L}*\\b", RegexOption.IGNORE_CASE)
        }
        "CAMEX" -> {
            patterns += Regex("\\bCAMEX\\p{L}*\\b", RegexOption.IGNORE_CASE)
        }
        "KARGOFLEX" -> {
            patterns += Regex("\\bKARGO?FLEX\\p{L}*\\b", RegexOption.IGNORE_CASE)
        }
        "CAMARATC" -> {
            patterns += Regex("\\bCAMARATC\\p{L}*\\b", RegexOption.IGNORE_CASE)
        }
        "POSTLINK" -> {
            patterns += Regex("\\bPOSTLINK\\p{L}*\\b", RegexOption.IGNORE_CASE)
        }
    }

    // общие бренды
    patterns += Regex("\\bTLS\\s+CARGO\\b", RegexOption.IGNORE_CASE)
    patterns += Regex("\\bE\\p{L}{0,2}PRESS\\p{L}*\\b", RegexOption.IGNORE_CASE)
    patterns += Regex("\\bEXP\\b", RegexOption.IGNORE_CASE)
    patterns += Regex("\\bCARGO\\b", RegexOption.IGNORE_CASE)
    patterns += Regex("\\bSHIP\\b", RegexOption.IGNORE_CASE)

    patterns.forEach { re -> line = re.replace(line, " ") }

    // убрать код ячейки
    if (!cellCode.isNullOrBlank()) {
        val reCell = Regex("\\b${Regex.escape(cellCode)}\\b", RegexOption.IGNORE_CASE)
        line = reCell.replace(line, " ")
    }

    // выкинуть ведущий "код" с цифрами
    line = Regex("^\\s*\\S*\\d+\\S*\\s+", RegexOption.IGNORE_CASE).replace(line, " ")

    // хвосты-организации
    line = Regex(
        "\\b(exp|cargo|llc|gmbh|gimbh|shop|online|spa|hub)\\b\\.?$",
        RegexOption.IGNORE_CASE
    ).replace(line, "")

    // нормализация пробелов/дефисов
    line = Regex("\\s*[-–—]+\\s*").replace(line, " ")
    line = line.trim(' ', '\t', '-', ',', ':', '.', ';', '/')
    line = Regex("\\s{2,}").replace(line, " ")

    if (line.isBlank()) return null

    // если одно и то же имя повторено через разделители – оставляем один раз
    val parts = Regex("\\s*[-–—,:;/]+\\s*")
        .split(line)
        .map { it.trim() }
        .filter { it.isNotEmpty() }

    if (parts.size > 1) {
        val lowerUnique = parts.map { it.lowercase() }.toSet()
        if (lowerUnique.size == 1) {
            line = parts.first()
        }
    }

    return if (line.isBlank()) null else line
}
fun detectClientName(
    text: String,
    forwarderCode: String?,
    cellCode: String?,
    nameDict: NameDict?
): String? {
    val lines = text.lines()
        .map { it.trim() }
        .filter { it.isNotEmpty() }

    if (lines.isEmpty()) return null

    // 1) попробуем сначала строки рядом с форвардером
    val forwarderAliases: Map<String, List<String>> = mapOf(
        "COLIBRI"   to listOf("colibri express", "colibriexpress", "colibri exp", "colibri"),
        "KOLLI"     to listOf("koliexpress", "koli express", "koli-express", "koliexp", "koli"),
        "ASER"      to listOf("aser express", "aser exp", "aser"),
        "CAMEX"     to listOf("camex", "camex llc", "camex express"),
        "KARGOFLEX" to listOf("kargoflex"),
        "CAMARATC"  to listOf("camaratc"),
        "POSTLINK"  to listOf("postlink")
    )

    if (forwarderCode != null && forwarderAliases.containsKey(forwarderCode)) {
        val aliases = forwarderAliases[forwarderCode]!!
        val lowerLines = lines.map { it.lowercase() }

        for (i in lowerLines.indices) {
            val l = lowerLines[i]
            if (aliases.any { it in l }) {
                // проверяем текущую строку и следующую
                val idxs = listOf(i, i + 1).filter { it in lines.indices }
                for (idx in idxs) {
                    val cleaned = cleanNameLine(lines[idx], forwarderCode, cellCode)
                    if (cleaned != null && looksLikePersonName(cleaned, nameDict)) {
                        return cleaned
                    }
                }
            }
        }
    }

    // 2) fallback – любая строка, похожая на имя
    for (line in lines) {
        val cleaned = cleanNameLine(line, forwarderCode, cellCode)
        if (cleaned != null && looksLikePersonName(cleaned, nameDict)) {
            return cleaned
        }
    }

    return null
}

fun detectLocalCarrierName(textRaw: String): String? {
    val t = textRaw.lowercase()

    return when {
        Regex("\\bhermes\\b").containsMatchIn(t) -> "HERMES"
        Regex("\\bgls\\b").containsMatchIn(t) -> "GLS"
        Regex("\\bups\\b").containsMatchIn(t) || Regex("\\b1z[0-9a-z]{16}\\b").containsMatchIn(t) -> "UPS"
        Regex("\\bamazon\\b").containsMatchIn(t) || Regex("\\btba\\d{10,}\\b").containsMatchIn(t) -> "AMAZON"
        Regex("\\bdhl\\b").containsMatchIn(t) || t.contains("deutsche post") -> "DHL"
        else -> null
    }
}

fun detectLocalTrackingNo(textRaw: String, carrier: String?): String? {
    val text = textRaw.replace("\n", " ").replace("\r", " ").uppercase()

    // UPS
    Regex("\\b1Z[0-9A-Z]{16}\\b").find(text)?.let { return it.value }

    // Amazon
    Regex("\\bTBA\\d{10,}\\b").find(text)?.let { return it.value }

    // ID No / IDNO
    Regex("\\bID\\s*NO\\b\\s*[:#\\-]?\\s*(\\d{8,20})\\b").find(text)?.let {
        return it.groupValues[1]
    }

    // digits candidates 8..20, но режем телефоны
    val matches = Regex("\\b\\d{8,20}\\b").findAll(text).toList()
    if (matches.isEmpty()) return null

    fun isPhoneLike(m: MatchResult): Boolean {
        val start = (m.range.first - 20).coerceAtLeast(0)
        val ctx = text.substring(start, m.range.first)
        return ctx.contains("PHONE") || ctx.contains("TEL") || ctx.contains("CONTACT")
    }

    val candidates = matches
        .filterNot { isPhoneLike(it) }
        .map { it.value }

    if (candidates.isEmpty()) return null

    fun score(v: String): Int {
        var s = v.length

        // типовые подсказки по длине
        if (carrier == "GLS" && v.length == 11) s += 50
        if (carrier == "HERMES" && v.length in 14..16) s += 50
        if (carrier == "DHL" && v.length >= 12) s += 20

        return s
    }

    return candidates.maxByOrNull { score(it) }
}



fun normalizeCountryForSelect(raw: String?): String? {
    val c = raw?.trim()?.takeIf { it.isNotBlank() } ?: return null
    return when (c.uppercase()) {
        "AZB", "AZE", "AZE" -> "AZ"
        "TBS", "GEO"        -> "GE"
        "DEU", "DE"         -> "DE"
        "KGZ", "KG"         -> "KG"
        else                -> c.uppercase()
    }
}

fun detectTuid(textRaw: String): String? {
    val lines = textRaw.lines().map { it.trim() }.filter { it.isNotEmpty() }

    val re = Regex(
        "(?i)\\b(colibri\\s*express|colibriexpress|koli\\s*express|koliexpress|camex|aser\\s*express|postlink|kargoflex|camaratc)\\b\\s*[-–—:]?\\s*([A-Z]{1,3}\\d{3,8})\\b"
    )

    for (line in lines) {
        val m = re.find(line) ?: continue
        val fw = m.groupValues[1].uppercase().replace(Regex("\\s+"), " ").trim()
        val cell = m.groupValues[2].uppercase()
        return "$fw -$cell"
    }
    return null
}
fun detectForwarderByText(textRaw: String): String? {
    val t = textRaw.lowercase()

    return when {
        Regex("\\bcolibri\\b").containsMatchIn(t) -> "COLIBRI"
        Regex("\\b(koli|koliexp|koliexpress|koli\\s*express)\\b").containsMatchIn(t) -> "KOLLI"
        Regex("\\bcamex\\b").containsMatchIn(t) -> "CAMEX"
        Regex("\\baser\\b").containsMatchIn(t) -> "ASER"
        Regex("\\bpostlink\\b").containsMatchIn(t) -> "POSTLINK"
        Regex("\\bkargoflex\\b").containsMatchIn(t) -> "KARGOFLEX"
        Regex("\\bcamaratc\\b").containsMatchIn(t) -> "CAMARATC"
        else -> null
    }
}



fun buildLiveOcrPreview(
    fullText: String,
    destConfig: List<DestCountryCfg>,
    nameDict: NameDict?
): String {
    val basic = parseOcrText(fullText)
    val advanced = buildOcrParcelDataFromText(
        fullText = fullText,
        trackingNo = basic.trackingNo,
        destConfig = destConfig,
        nameDict = nameDict
    )
    val merged = basic.copy(
        receiverCountryCode = advanced.receiverCountryCode ?: basic.receiverCountryCode,
        receiverCompany = advanced.receiverCompany ?: basic.receiverCompany,
        receiverForwarderCode = advanced.receiverForwarderCode ?: basic.receiverForwarderCode,
        receiverCellCode = advanced.receiverCellCode ?: basic.receiverCellCode,
        receiverName = advanced.receiverName ?: basic.receiverName
    )

    val localCarrier = detectLocalCarrierName(fullText)
    val localTracking = detectLocalTrackingNo(fullText, localCarrier)
    val tuid = detectTuid(fullText)

    val items = mutableListOf<String>()
    fun add(label: String, value: String?) {
        val clean = value?.trim()?.takeIf { it.isNotEmpty() } ?: return
        items += "$label: $clean"
    }

    add("Tracking", merged.trackingNo)
    add("TUID", tuid)
    add("Country", merged.receiverCountryCode)
    add("Forwarder", merged.receiverForwarderCode)
    add("Company", merged.receiverCompany)
    add("Cell", merged.receiverCellCode)
    add("Name", merged.receiverName)
    add("Local carrier", localCarrier)
    add("Local tracking", localTracking)

    if (items.isEmpty()) {
        return fullText.lines()
            .map { it.trim() }
            .firstOrNull { it.isNotEmpty() }
            ?.take(64)
            ?: "данные не распознаны"
    }

    return items
        .take(5)
        .joinToString("\n")
}


data class ScanAction(
    val action: String? = null,
    val fieldId: String? = null,
    val fieldName: String? = null,

    // NEW: мультизаполнение
    val fieldIds: List<String>? = null,
    val fieldNames: List<String>? = null,

    val endpoint: String? = null,
    // NEW: куда писать cell_id из qr_check (id селекта)
    val applyToSelectId: String? = null,
    // NEW: имя JavaScript функции для вызова
    val callback: String? = null
)

data class ScanContextConfig(
    val activeTabSelector: String? = null,
    val barcode: ScanAction? = null,
    val qr: ScanAction? = null,
    val flow: FlowConfig? = null
)

data class ScanTaskUiConfig(
    val title: String? = null,
    val stepLabels: Map<String, String> = emptyMap()
)

data class FlowConfig(
    val start: String,
    val steps: Map<String, FlowStep>
)

data class FlowStep(
    val nextOnScan: String? = null,
    val onAction: Map<String, List<FlowOp>> = emptyMap(),
    val mode: String? = null,
    val barcodeAction: ScanAction? = null,
    val qrAction: ScanAction? = null
)

sealed interface FlowOp {
    data class OpenScanner(val mode: String) : FlowOp
    data class Web(val name: String) : FlowOp
    data class SetStep(val to: String) : FlowOp
    data object Noop : FlowOp
    data class WebIf(
        val cond: String,
        val thenOps: List<FlowOp>,
        val elseOps: List<FlowOp>
    ) : FlowOp
}
/**
 * device-scan-config поддерживает per-tab контексты:
 *
 * {
 *   "contexts": {
 *     "scanner": {
 *       "active_tab_selector": "#warehouse-move-scanner-tab.nav-link.active",
 *       "barcode": { "action": "fill_field", "field_id": "warehouse-move-search" },
 *       "qr":      { "action": "api_check", "endpoint": "/api/qr_check.php" }
 *     }
 *   },
 *   "buttons": {
 *     "vol_down_single": "scan",
 *     "vol_down_double": "confirm"
 *   }
 * }
 */
data class ScanTaskConfig(
    val taskId: String,
    val defaultMode: String,   // "ocr" | "barcode" | "qr"
    val modes: Set<String>,
    val barcodeAction: ScanAction? = null,
    val qrAction: ScanAction? = null,
    val cellNullDefaultForward: Map<String, String> = emptyMap(),
    val contexts: Map<String, ScanContextConfig> = emptyMap(),
    // NEW: сервер/страница может явно указать активный контекст
    // (DeviceScanConfig.setActiveContext(...) обновляет это поле в <script id="device-scan-config">)
    val activeContext: String? = null,
    val buttons: Map<String, String> = emptyMap(),
    val flow: FlowConfig? = null,
    val api: Map<String, String> = emptyMap(),
    val ui: ScanTaskUiConfig? = null
)


fun parseScanAction(obj: JSONObject?): ScanAction? {
    if (obj == null) return null

    fun optStringList(key: String): List<String>? {
        if (!obj.has(key)) return null
        val v = obj.opt(key) ?: return null

        val list = when (v) {
            is JSONArray -> (0 until v.length())
                .mapNotNull { idx -> v.optString(idx, null)?.trim() }
                .filter { it.isNotEmpty() }

            is String -> v.split(",")
                .map { it.trim() }
                .filter { it.isNotEmpty() }

            else -> emptyList()
        }
        return list.takeIf { it.isNotEmpty() }
    }

    val action = obj.optString("action", null)
    val endpoint = obj.optString("endpoint", null)

    val fieldIds = optStringList("field_ids") ?: optStringList("field_id")
    val fieldNames = optStringList("field_names") ?: optStringList("field_name")

    // NEW: поддержка apply_to_select_id (и на всякий случай camelCase)
    val applyToSelectId =
        obj.optString("apply_to_select_id", "").trim().takeIf { it.isNotEmpty() }
            ?: obj.optString("applyToSelectId", "").trim().takeIf { it.isNotEmpty() }

    val callback = obj.optString("callback", "").trim().takeIf { it.isNotEmpty() }

    return ScanAction(
        action = action,
        fieldId = obj.optString("field_id", null),
        fieldName = obj.optString("field_name", null),
        fieldIds = fieldIds,
        fieldNames = fieldNames,
        endpoint = endpoint,
        applyToSelectId = applyToSelectId,
        callback = callback

    )
}


fun parseFlowOpsArray(arr: JSONArray?): List<FlowOp> {
    if (arr == null) return emptyList()
    val result = mutableListOf<FlowOp>()
    for (i in 0 until arr.length()) {
        val op = parseFlowOp(arr.optJSONObject(i))
        if (op != null) result += op
    }
    return result
}

fun parseFlowOp(obj: JSONObject?): FlowOp? {
    if (obj == null) return null
    return when (obj.optString("op", "").trim().lowercase()) {
        "open_scanner" -> {
            val mode = obj.optString("mode", "").trim().lowercase()
            if (mode.isBlank()) null else FlowOp.OpenScanner(mode)
        }
        "web" -> {
            val name = obj.optString("name", "").trim()
            if (name.isBlank()) null else FlowOp.Web(name)
        }
        "set_step" -> {
            val to = obj.optString("to", "").trim().lowercase()
            if (to.isBlank()) null else FlowOp.SetStep(to)
        }
        "noop" -> FlowOp.Noop
        "web_if" -> {
            val cond = obj.optString("cond", "").trim().lowercase()
            val thenOps = parseFlowOpsArray(obj.optJSONArray("then"))
            val elseOps = parseFlowOpsArray(obj.optJSONArray("else"))
            if (cond.isBlank()) null else FlowOp.WebIf(cond, thenOps, elseOps)
        }
        else -> null
    }
}

fun parseFlowConfig(flowObj: JSONObject?): FlowConfig? {
    if (flowObj == null) return null

    val start = flowObj.optString("start", "").trim().lowercase()
    if (start.isBlank()) return null

    val stepsObj = flowObj.optJSONObject("steps")
    val steps = mutableMapOf<String, FlowStep>()

    if (stepsObj != null) {
        val stepNames = stepsObj.names()
        if (stepNames != null) {
            for (i in 0 until stepNames.length()) {
                val stepKey = stepNames.optString(i, "").trim().lowercase()
                val stepObj = stepsObj.optJSONObject(stepKey) ?: continue

                val nextOnScan = stepObj.optString("next_on_scan", "").trim().lowercase()
                    .takeIf { it.isNotBlank() }
                val mode = stepObj.optString("mode", "").trim().lowercase()
                    .takeIf { it.isNotBlank() }

                // Парсим действия для этого шага
                val barcodeAction = parseScanAction(stepObj.optJSONObject("barcode"))
                val qrAction = parseScanAction(stepObj.optJSONObject("qr"))

                // Парсим on_action
                val onActionObj = stepObj.optJSONObject("on_action")
                val onAction = mutableMapOf<String, List<FlowOp>>()
                if (onActionObj != null) {
                    val actionNames = onActionObj.names()
                    if (actionNames != null) {
                        for (j in 0 until actionNames.length()) {
                            val actionKey = actionNames.optString(j, "").trim().lowercase()
                            val ops = parseFlowOpsArray(onActionObj.optJSONArray(actionKey))
                            if (actionKey.isNotBlank()) {
                                onAction[actionKey] = ops
                            }
                        }
                    }
                }

                if (stepKey.isNotBlank()) {
                    steps[stepKey] = FlowStep(
                        nextOnScan = nextOnScan,
                        onAction = onAction,
                        mode = mode,
                        barcodeAction = barcodeAction,
                        qrAction = qrAction
                    )
                }
            }
        }
    }

    return if (steps.isNotEmpty()) FlowConfig(start, steps) else null
}

fun parseScanTaskConfig(json: String): ScanTaskConfig? = try {
    val obj = JSONObject(json)

    val taskId = obj.optString("task_id", "unknown").trim().lowercase()
    val def = obj.optString("default_mode", "ocr").trim().lowercase()
    // NEW: active_context (например: "scanner", "batch", "scanner_modal")
    val activeContext = obj.optString("active_context", null)?.trim()?.takeIf { it.isNotEmpty() }
    val arr = obj.optJSONArray("modes")
    val modes = mutableSetOf<String>()
    if (arr != null) {
        for (i in 0 until arr.length()) {
            val m = arr.optString(i, "").trim().lowercase()
            if (m.isNotBlank()) modes += m
        }
    }

    val barcode = parseScanAction(obj.optJSONObject("barcode"))
    val qr = parseScanAction(obj.optJSONObject("qr"))

    // поддержка и правильного, и кривого ключа
    val cellDefaultsObj =
        obj.optJSONObject("cell_null_default_forward")
            ?: obj.optJSONObject("cell_null_default_forwrad")

    val cellDefaults = mutableMapOf<String, String>()
    if (cellDefaultsObj != null) {
        val names = cellDefaultsObj.names()
        if (names != null) {
            for (i in 0 until names.length()) {
                val rawKey = names.optString(i, "")
                val key = rawKey.trim().uppercase()
                val value = cellDefaultsObj.optString(rawKey, "").trim()
                if (key.isNotBlank() && value.isNotBlank()) {
                    cellDefaults[key] = value
                }
            }
        }
    }

    val contextsObj = obj.optJSONObject("contexts")
    val contexts = mutableMapOf<String, ScanContextConfig>()
    if (contextsObj != null) {
        val names = contextsObj.names()
        if (names != null) {
            for (i in 0 until names.length()) {
                val key = names.optString(i, "").trim()
                val ctxObj = contextsObj.optJSONObject(key) ?: continue
                val selector = ctxObj.optString("active_tab_selector", "").trim().takeIf { it.isNotEmpty() }
                val barcodeCtx = parseScanAction(ctxObj.optJSONObject("barcode"))
                val qrCtx = parseScanAction(ctxObj.optJSONObject("qr"))

                // НОВОЕ: парсим flow внутри контекста
                val contextFlow = parseFlowConfig(ctxObj.optJSONObject("flow"))

                contexts[key] = ScanContextConfig(
                    activeTabSelector = selector,
                    barcode = barcodeCtx,
                    qr = qrCtx,
                    flow = contextFlow
                )
            }
        }
    }

    val buttonsObj = obj.optJSONObject("buttons")
    val buttons = mutableMapOf<String, String>()
    if (buttonsObj != null) {
        val names = buttonsObj.names()
        if (names != null) {
            for (i in 0 until names.length()) {
                val key = names.optString(i, "").trim()
                val value = buttonsObj.optString(key, "").trim().lowercase()
                if (key.isNotBlank() && value.isNotBlank()) {
                    buttons[key] = value
                }
            }
        }
    }


    val flow = parseFlowConfig(obj.optJSONObject("flow"))

    val uiObj = obj.optJSONObject("ui")
    val uiTitle = uiObj?.optString("title", "")?.trim()?.takeIf { it.isNotEmpty() }
    val stepLabelsObj = uiObj?.optJSONObject("step_labels")
    val stepLabels = mutableMapOf<String, String>()
    if (stepLabelsObj != null) {
        val names = stepLabelsObj.names()
        if (names != null) {
            for (i in 0 until names.length()) {
                val key = names.optString(i, "").trim().lowercase()
                val value = stepLabelsObj.optString(key, "").trim()
                if (key.isNotBlank() && value.isNotBlank()) {
                    stepLabels[key] = value
                }
            }
        }
    }

    val apiObj = obj.optJSONObject("api")
    val api = mutableMapOf<String, String>()
    if (apiObj != null) {
        val names = apiObj.names()
        if (names != null) {
            for (i in 0 until names.length()) {
                val key = names.optString(i, "").trim()
                val value = apiObj.optString(key, "").trim()
                if (key.isNotBlank() && value.isNotBlank()) {
                    api[key] = value
                }
            }
        }
    }


    ScanTaskConfig(
        taskId = taskId,
        defaultMode = def,
        modes = modes,
        barcodeAction = barcode,
        qrAction = qr,
        cellNullDefaultForward = cellDefaults,
        contexts = contexts,
        activeContext = activeContext,
        buttons = buttons,
        flow = flow,
        api = api,
        ui = if (uiTitle != null || stepLabels.isNotEmpty()) {
            ScanTaskUiConfig(
                title = uiTitle,
                stepLabels = stepLabels
            )
        } else {
            null
        }
    )
} catch (e: Exception) {
    e.printStackTrace()
    null
}

// ==========================================
// ВСТАВИТЬ В САМЫЙ КОНЕЦ MainActivity.kt
// ==========================================

fun fieldSelector(action: ScanAction?): String? {
    val fieldId = action?.fieldId?.trim()?.takeIf { it.isNotEmpty() }
    val fieldName = action?.fieldName?.trim()?.takeIf { it.isNotEmpty() }
    return when {
        fieldId != null -> "#$fieldId"
        fieldName != null -> "[name=\"${escapeJsSelector(fieldName)}\"]"
        else -> null
    }
}

fun clearWarehouseMoveState(webView: WebView?, contextConfig: ScanContextConfig) {
    val field = fieldSelector(contextConfig.barcode)
    val selectId = contextConfig.qr?.applyToSelectId
    webView?.let { web ->
        field?.let { setInputValueBySelector(web, it, "") }
        selectId?.let { setSelectValueById(web, it, "") }
    }
}

fun handleWarehouseMoveConfirm(
    webView: WebView?,
    contextKey: String,
    contextConfig: ScanContextConfig,
    scope: CoroutineScope,
    config: DeviceConfig,
    taskConfig: ScanTaskConfig?,
    scannerCellId: String?,
    batchCellId: String?
) {
    val moveEndpoint = taskConfig?.api?.get("move_apply") ?: return
    val trackingField = fieldSelector(contextConfig.barcode) ?: return

    val isBatchContext = contextKey == "batch" || contextConfig.qr?.applyToSelectId != null
    val cellId = if (isBatchContext) batchCellId else scannerCellId
    if (cellId.isNullOrBlank()) return

    webView?.let { web ->
        getInputValueBySelector(web, trackingField) { tracking ->
            val cleanTracking = tracking?.trim()?.takeIf { it.isNotEmpty() } ?: return@getInputValueBySelector
            scope.launch {
                val result = applyWarehouseMoveWithSession(
                    cfg = config,
                    endpoint = moveEndpoint,
                    tracking = cleanTracking,
                    cellId = cellId,
                    mode = if (contextKey.isEmpty()) "default" else contextKey
                )
                if (result.ok) {
                    setInputValueBySelector(web, trackingField, "")
                }
            }
        }
    }
}

fun handleNativeWebOp(
    webView: WebView,
    opName: String,
    contextConfig: ScanContextConfig?,
    scope: CoroutineScope,
    config: DeviceConfig,
    taskConfig: ScanTaskConfig?,
    scannerCellId: String?,
    batchCellId: String?
): Boolean {
    return when (opName) {
        "clear_search" -> {
            val field = fieldSelector(contextConfig?.barcode) ?: "#warehouse-move-search"
            setInputValueBySelector(webView, field, "")
            true
        }
        "reset_form" -> {
            contextConfig?.let { clearWarehouseMoveState(webView, it) }
            clearWarehouseMoveResultsInWebView(webView)
            true
        }
        "apply_move" -> {
            contextConfig?.let {
                handleWarehouseMoveConfirm(
                    webView = webView,
                    contextKey = "",
                    contextConfig = it,
                    scope = scope,
                    config = config,
                    taskConfig = taskConfig,
                    scannerCellId = scannerCellId,
                    batchCellId = batchCellId
                )
            }
            true
        }
        "add_new_item" -> {
            prepareFormForNextScanInWebView(webView)
            true
        }
        "upload_item_stock_photo_label" -> {
            captureWarehouseItemStockPhotoInWebView(webView, "label")
            true
        }
        "upload_item_stock_photo_box" -> {
            captureWarehouseItemStockPhotoInWebView(webView, "box")
            true
        }
        else -> false
    }
}
