package com.foleyit.foleytimepro

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.*
import androidx.appcompat.app.AppCompatActivity
import com.foleyit.foleytimepro.network.ApiClient
import com.foleyit.foleytimepro.utils.PrefsManager
import java.net.URL

class SetupActivity : AppCompatActivity() {

    private lateinit var prefs: PrefsManager
    private lateinit var urlInput: EditText
    private lateinit var validateBtn: Button
    private lateinit var progress: ProgressBar
    private lateinit var statusText: TextView
    private lateinit var companyCard: LinearLayout
    private lateinit var companyName: TextView
    private lateinit var continueBtn: Button
    private var confirmedUrl = ""

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_setup)
        prefs        = PrefsManager(this)
        urlInput     = findViewById(R.id.urlInput)
        validateBtn  = findViewById(R.id.validateBtn)
        progress     = findViewById(R.id.progressBar)
        statusText   = findViewById(R.id.statusText)
        companyCard  = findViewById(R.id.companyCard)
        companyName  = findViewById(R.id.companyNameText)
        continueBtn  = findViewById(R.id.continueBtn)

        validateBtn.setOnClickListener { validateUrl() }
        continueBtn.setOnClickListener {
            prefs.setInstanceUrl(confirmedUrl)
            startActivity(Intent(this, LoginActivity::class.java))
            finish()
        }
    }

    private fun validateUrl() {
        val raw = urlInput.text.toString().trim()
        if (raw.isEmpty()) { showError("Enter a server URL"); return }

        val url = if (raw.startsWith("http")) raw.trimEnd('/') else "https://$raw".trimEnd('/')
        try { URL(url) } catch (_: Exception) { showError("Invalid URL format"); return }

        setLoading(true)
        companyCard.visibility = View.GONE
        statusText.visibility  = View.GONE

        Thread {
            val result = ApiClient(url).fetchBranding()
            runOnUiThread {
                setLoading(false)
                if (result.success) {
                    confirmedUrl = url
                    prefs.saveCompanyName(result.companyName)
                    companyName.text = result.companyName
                    companyCard.visibility = View.VISIBLE
                    continueBtn.visibility = View.VISIBLE
                } else {
                    showError(result.error)
                }
            }
        }.start()
    }

    private fun showError(msg: String) {
        statusText.text = msg
        statusText.visibility = View.VISIBLE
    }

    private fun setLoading(on: Boolean) {
        progress.visibility    = if (on) View.VISIBLE else View.GONE
        validateBtn.isEnabled  = !on
    }
}
