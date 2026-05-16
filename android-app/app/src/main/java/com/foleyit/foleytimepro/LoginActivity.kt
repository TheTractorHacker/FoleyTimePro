package com.foleyit.foleytimepro

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.webkit.CookieManager
import android.widget.*
import androidx.appcompat.app.AppCompatActivity
import com.foleyit.foleytimepro.network.ApiClient
import com.foleyit.foleytimepro.network.LoginResult
import com.foleyit.foleytimepro.utils.PrefsManager

class LoginActivity : AppCompatActivity() {

    private lateinit var prefs: PrefsManager
    private lateinit var companyLabel: TextView
    private lateinit var emailInput: EditText
    private lateinit var passwordInput: EditText
    private lateinit var twoFaSection: LinearLayout
    private lateinit var twoFaInput: EditText
    private lateinit var loginBtn: Button
    private lateinit var progress: ProgressBar
    private lateinit var errorText: TextView
    private lateinit var changeServer: TextView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_login)
        prefs         = PrefsManager(this)
        companyLabel  = findViewById(R.id.companyLabel)
        emailInput    = findViewById(R.id.emailInput)
        passwordInput = findViewById(R.id.passwordInput)
        twoFaSection  = findViewById(R.id.twoFaSection)
        twoFaInput    = findViewById(R.id.twoFaInput)
        loginBtn      = findViewById(R.id.loginBtn)
        progress      = findViewById(R.id.progressBar)
        errorText     = findViewById(R.id.errorText)
        changeServer  = findViewById(R.id.changeServer)

        companyLabel.text = prefs.getCompanyName()

        loginBtn.setOnClickListener { doLogin() }
        changeServer.setOnClickListener {
            prefs.clearAll()
            startActivity(Intent(this, SetupActivity::class.java))
            finish()
        }
    }

    private fun doLogin(twoFaCode: String? = null) {
        val email = emailInput.text.toString().trim()
        val pass  = passwordInput.text.toString()
        if (email.isEmpty() || pass.isEmpty()) { showError("Email and password are required"); return }

        setLoading(true)
        errorText.visibility = View.GONE

        Thread {
            val result = ApiClient(prefs.getInstanceUrl()).login(email, pass, twoFaCode)
            runOnUiThread {
                setLoading(false)
                when {
                    result.success      -> onSuccess(result)
                    result.requires2FA  -> show2FA()
                    else                -> showError(result.error)
                }
            }
        }.start()
    }

    private fun show2FA() {
        twoFaSection.visibility = View.VISIBLE
        loginBtn.text = "Verify & Sign In"
        loginBtn.setOnClickListener { doLogin(twoFaInput.text.toString().trim()) }
    }

    private fun onSuccess(result: LoginResult) {
        prefs.saveTokens(result.accessToken, result.refreshToken, result.expiresIn)
        prefs.saveUser(result.userId, result.email, result.firstName, result.lastName)

        setLoading(true)
        Thread {
            val session = ApiClient(prefs.getInstanceUrl()).createWebViewSession(result.accessToken)
            runOnUiThread {
                if (session.success) {
                    val mgr = CookieManager.getInstance()
                    session.cookies.forEach { mgr.setCookie(prefs.getInstanceUrl(), it) }
                    mgr.flush()
                }
                setLoading(false)
                startActivity(Intent(this, HomeActivity::class.java))
                finish()
            }
        }.start()
    }

    private fun showError(msg: String) {
        errorText.text = msg
        errorText.visibility = View.VISIBLE
    }

    private fun setLoading(on: Boolean) {
        progress.visibility   = if (on) View.VISIBLE else View.GONE
        loginBtn.isEnabled    = !on
        emailInput.isEnabled  = !on
        passwordInput.isEnabled = !on
    }
}
