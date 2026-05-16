package com.foleyit.foleytimepro

import android.content.Intent
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.LayoutInflater
import android.view.View
import android.widget.*
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import com.foleyit.foleytimepro.network.ApiClient
import com.foleyit.foleytimepro.network.PunchEntry
import com.foleyit.foleytimepro.network.PunchStatus
import com.foleyit.foleytimepro.utils.PrefsManager

class HomeActivity : AppCompatActivity() {

    private lateinit var prefs: PrefsManager
    private lateinit var api: ApiClient

    private lateinit var swipeRefresh: SwipeRefreshLayout
    private lateinit var companyName: TextView
    private lateinit var employeeName: TextView
    private lateinit var statusBadge: TextView
    private lateinit var elapsedTimer: TextView
    private lateinit var clockInLabel: TextView
    private lateinit var noteInput: com.google.android.material.textfield.TextInputEditText
    private lateinit var btnClockIn: Button
    private lateinit var btnLunchStart: Button
    private lateinit var btnLunchEnd: Button
    private lateinit var btnClockOut: Button
    private lateinit var actionProgress: ProgressBar
    private lateinit var actionMessage: TextView
    private lateinit var weekTotal: TextView
    private lateinit var historyContainer: LinearLayout
    private lateinit var historyEmpty: TextView
    private lateinit var logoutBtn: Button

    private val timerHandler = Handler(Looper.getMainLooper())
    private var elapsedBase = 0L   // seconds at last status load
    private var timerRunning = false

    private val timerTick = object : Runnable {
        override fun run() {
            if (!timerRunning) return
            elapsedBase++
            elapsedTimer.text = formatElapsed(elapsedBase)
            timerHandler.postDelayed(this, 1000)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_home)

        prefs = PrefsManager(this)
        api   = ApiClient(prefs.getInstanceUrl())

        swipeRefresh    = findViewById(R.id.swipeRefresh)
        companyName     = findViewById(R.id.companyName)
        employeeName    = findViewById(R.id.employeeName)
        statusBadge     = findViewById(R.id.statusBadge)
        elapsedTimer    = findViewById(R.id.elapsedTimer)
        clockInLabel    = findViewById(R.id.clockInLabel)
        noteInput       = findViewById(R.id.noteInput)
        btnClockIn      = findViewById(R.id.btnClockIn)
        btnLunchStart   = findViewById(R.id.btnLunchStart)
        btnLunchEnd     = findViewById(R.id.btnLunchEnd)
        btnClockOut     = findViewById(R.id.btnClockOut)
        actionProgress  = findViewById(R.id.actionProgress)
        actionMessage   = findViewById(R.id.actionMessage)
        weekTotal       = findViewById(R.id.weekTotal)
        historyContainer= findViewById(R.id.historyContainer)
        historyEmpty    = findViewById(R.id.historyEmpty)
        logoutBtn       = findViewById(R.id.logoutBtn)

        companyName.text = prefs.getCompanyName()

        swipeRefresh.setOnRefreshListener { loadAll() }
        swipeRefresh.setColorSchemeResources(R.color.colorPrimary)

        btnClockIn.setOnClickListener    { submitPunch("clockin") }
        btnLunchStart.setOnClickListener { submitPunch("lunchstart") }
        btnLunchEnd.setOnClickListener   { submitPunch("lunchend") }
        btnClockOut.setOnClickListener   { submitPunch("clockout") }

        logoutBtn.setOnClickListener {
            AlertDialog.Builder(this)
                .setTitle("Logout")
                .setMessage("Are you sure you want to logout?")
                .setPositiveButton("Logout") { _, _ ->
                    prefs.clearSession()
                    startActivity(Intent(this, LoginActivity::class.java))
                    finish()
                }
                .setNegativeButton("Cancel", null)
                .show()
        }

        loadAll()
    }

    override fun onResume() {
        super.onResume()
        loadAll()
    }

    override fun onPause() {
        super.onPause()
        stopTimer()
    }

    private fun loadAll() {
        if (prefs.isTokenExpired()) {
            refreshTokenThenLoad()
            return
        }
        loadStatus()
        loadHistory()
    }

    private fun refreshTokenThenLoad() {
        Thread {
            val result = api.refreshToken(prefs.getRefreshToken())
            runOnUiThread {
                if (result.success) {
                    prefs.saveTokens(result.accessToken, result.refreshToken, result.expiresIn)
                    loadAll()
                } else {
                    prefs.clearSession()
                    startActivity(Intent(this, LoginActivity::class.java))
                    finish()
                }
            }
        }.start()
    }

    private fun loadStatus() {
        Thread {
            val status = api.getPunchStatus(prefs.getAccessToken())
            runOnUiThread {
                swipeRefresh.isRefreshing = false
                if (status.success) applyStatus(status)
                else showActionMessage(status.error, isError = true)
            }
        }.start()
    }

    private fun loadHistory() {
        Thread {
            val history = api.getPunchHistory(prefs.getAccessToken())
            runOnUiThread {
                if (history.success) {
                    weekTotal.text = "${history.totalHours} hrs"
                    renderHistory(history.punches)
                }
            }
        }.start()
    }

    private fun applyStatus(s: PunchStatus) {
        employeeName.text = s.name

        // Status badge color
        val (badgeColor, badgeText) = when (s.status) {
            "In"    -> Pair(R.color.colorStatusIn,    "CLOCKED IN")
            "Lunch" -> Pair(R.color.colorStatusLunch, "ON LUNCH")
            else    -> Pair(R.color.colorStatusOut,   "CLOCKED OUT")
        }
        val drawable = ContextCompat.getDrawable(this, R.drawable.badge_bg)?.mutate() as? GradientDrawable
        drawable?.setColor(ContextCompat.getColor(this, badgeColor))
        statusBadge.background = drawable
        statusBadge.text = badgeText

        // Clock-in label + timer
        if (s.clockInTime != null && s.status != "Out") {
            clockInLabel.text = "Clocked in at ${s.clockInTime}"
            clockInLabel.visibility = View.VISIBLE
            elapsedTimer.visibility = View.VISIBLE
            elapsedBase = s.elapsedSeconds
            elapsedTimer.text = formatElapsed(elapsedBase)
            if (s.status == "In") startTimer() else stopTimer()
        } else {
            clockInLabel.visibility = View.GONE
            elapsedTimer.visibility = View.GONE
            stopTimer()
        }

        // Button states based on status
        val isOut    = s.status == "Out"
        val isIn     = s.status == "In"
        val isLunch  = s.status == "Lunch"

        setButtonEnabled(btnClockIn,    isOut)
        setButtonEnabled(btnLunchStart, isIn)
        setButtonEnabled(btnLunchEnd,   isLunch)
        setButtonEnabled(btnClockOut,   isIn || isLunch)
    }

    private fun setButtonEnabled(btn: Button, enabled: Boolean) {
        btn.isEnabled = enabled
        btn.alpha = if (enabled) 1.0f else 0.45f
    }

    private fun submitPunch(action: String) {
        val note = noteInput.text?.toString()?.trim() ?: ""
        setActionsLoading(true)
        actionMessage.visibility = View.GONE

        Thread {
            val result = api.punch(prefs.getAccessToken(), action, note)
            runOnUiThread {
                setActionsLoading(false)
                noteInput.setText("")
                showActionMessage(result.message.ifEmpty { result.error }, isError = !result.success)
                if (result.success) loadAll()
            }
        }.start()
    }

    private fun setActionsLoading(on: Boolean) {
        actionProgress.visibility = if (on) View.VISIBLE else View.GONE
        btnClockIn.isEnabled    = !on
        btnLunchStart.isEnabled = !on
        btnLunchEnd.isEnabled   = !on
        btnClockOut.isEnabled   = !on
    }

    private fun showActionMessage(msg: String, isError: Boolean) {
        if (msg.isEmpty()) return
        actionMessage.text = msg
        actionMessage.setTextColor(ContextCompat.getColor(this,
            if (isError) R.color.colorError else R.color.colorStatusIn))
        actionMessage.visibility = View.VISIBLE
    }

    private fun renderHistory(punches: List<PunchEntry>) {
        historyContainer.removeAllViews()
        if (punches.isEmpty()) {
            historyEmpty.visibility = View.VISIBLE
            return
        }
        historyEmpty.visibility = View.GONE
        val inflater = LayoutInflater.from(this)
        punches.forEach { entry ->
            val row = inflater.inflate(android.R.layout.two_line_list_item, historyContainer, false)
            val text1 = row.findViewById<TextView>(android.R.id.text1)
            val text2 = row.findViewById<TextView>(android.R.id.text2)

            val inOut = "${entry.inTime ?: "—"} → ${entry.outTime ?: "open"}"
            text1.text = "${entry.date}  $inOut"
            text1.textSize = 14f
            text1.setTextColor(ContextCompat.getColor(this, R.color.colorTextPrimary))

            val lunch = if (entry.lunchStart != null) "Lunch ${entry.lunchStart}–${entry.lunchEnd ?: "?"}" else ""
            val hrs   = if (entry.hours != null) "${entry.hours} hrs" else ""
            text2.text = listOf(lunch, hrs).filter { it.isNotEmpty() }.joinToString("   ")
            text2.textSize = 12f
            text2.setTextColor(ContextCompat.getColor(this, R.color.colorTextSecondary))

            // Divider
            val divider = View(this).apply {
                layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 1).apply {
                    setMargins(0, 4, 0, 4)
                }
                setBackgroundColor(ContextCompat.getColor(this@HomeActivity, R.color.colorDivider))
            }
            historyContainer.addView(row)
            historyContainer.addView(divider)
        }
    }

    private fun startTimer() {
        if (timerRunning) return
        timerRunning = true
        timerHandler.postDelayed(timerTick, 1000)
    }

    private fun stopTimer() {
        timerRunning = false
        timerHandler.removeCallbacks(timerTick)
    }

    private fun formatElapsed(seconds: Long): String {
        val h = seconds / 3600
        val m = (seconds % 3600) / 60
        val s = seconds % 60
        return "%02d:%02d:%02d".format(h, m, s)
    }
}
