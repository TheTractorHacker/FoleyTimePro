package com.foleyit.foleytimepro

import android.annotation.SuppressLint
import android.content.Intent
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Bundle
import android.view.View
import android.webkit.*
import android.widget.Button
import android.widget.ProgressBar
import android.widget.RelativeLayout
import androidx.appcompat.app.AppCompatActivity
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import com.foleyit.foleytimepro.utils.PrefsManager

class HomeActivity : AppCompatActivity() {

    private lateinit var prefs: PrefsManager
    private lateinit var webView: WebView
    private lateinit var progress: ProgressBar
    private lateinit var swipe: SwipeRefreshLayout
    private lateinit var offlineView: RelativeLayout

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_home)

        prefs       = PrefsManager(this)
        webView     = findViewById(R.id.webView)
        progress    = findViewById(R.id.progressBar)
        swipe       = findViewById(R.id.swipeRefresh)
        offlineView = findViewById(R.id.offlineView)

        configureWebView()

        swipe.setOnRefreshListener { webView.reload() }
        findViewById<Button>(R.id.retryBtn).setOnClickListener { loadApp() }

        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState)
        } else {
            loadApp()
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun configureWebView() {
        webView.settings.apply {
            javaScriptEnabled        = true
            domStorageEnabled        = true
            loadsImagesAutomatically = true
            cacheMode                = WebSettings.LOAD_DEFAULT
            setGeolocationEnabled(true)
            mediaPlaybackRequiresUserGesture = false
            mixedContentMode         = WebSettings.MIXED_CONTENT_NEVER_ALLOW
        }

        webView.webViewClient = object : WebViewClient() {
            override fun onPageStarted(view: WebView, url: String, favicon: android.graphics.Bitmap?) {
                progress.visibility    = View.VISIBLE
                offlineView.visibility = View.GONE
            }

            override fun onPageFinished(view: WebView, url: String) {
                progress.visibility = View.GONE
                swipe.isRefreshing  = false
                // Redirect to native login when PHP session expires
                if (url.contains("/user/login.php") || url.endsWith("/login")) {
                    prefs.clearSession()
                    startActivity(Intent(this@HomeActivity, LoginActivity::class.java))
                    finish()
                }
            }

            override fun onReceivedError(view: WebView, req: WebResourceRequest, err: WebResourceError) {
                progress.visibility = View.GONE
                swipe.isRefreshing  = false
                if (!isOnline()) offlineView.visibility = View.VISIBLE
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onGeolocationPermissionsShowPrompt(origin: String, cb: GeolocationPermissions.Callback) {
                cb.invoke(origin, true, false)
            }
        }
    }

    private fun loadApp() {
        if (!isOnline()) { offlineView.visibility = View.VISIBLE; return }
        offlineView.visibility = View.GONE
        webView.loadUrl("${prefs.getInstanceUrl()}/user/dashboard.php")
    }

    override fun onBackPressed() {
        if (webView.canGoBack()) webView.goBack() else super.onBackPressed()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        webView.saveState(outState)
    }

    private fun isOnline(): Boolean {
        val cm    = getSystemService(CONNECTIVITY_SERVICE) as ConnectivityManager
        val net   = cm.activeNetwork ?: return false
        val caps  = cm.getNetworkCapabilities(net) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }
}
