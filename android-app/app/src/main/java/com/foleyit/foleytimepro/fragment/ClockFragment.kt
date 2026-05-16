package com.foleyit.foleytimepro.fragment

import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.*
import android.view.animation.AnimationUtils
import android.widget.*
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import com.foleyit.foleytimepro.R
import com.foleyit.foleytimepro.network.ApiClient
import com.foleyit.foleytimepro.network.PunchEntry
import com.foleyit.foleytimepro.network.PunchStatus
import com.foleyit.foleytimepro.utils.PrefsManager
import com.google.android.material.button.MaterialButton
import com.google.android.material.textfield.TextInputEditText

class ClockFragment : Fragment() {

    private lateinit var prefs: PrefsManager
    private lateinit var api: ApiClient

    private lateinit var swipeRefresh: SwipeRefreshLayout
    private lateinit var statusCard: LinearLayout
    private lateinit var tvGreeting: TextView
    private lateinit var tvName: TextView
    private lateinit var tvStatusBadge: TextView
    private lateinit var tvTimer: TextView
    private lateinit var tvClockInTime: TextView
    private lateinit var etNote: TextInputEditText
    private lateinit var btnClockIn: MaterialButton
    private lateinit var btnLunchStart: MaterialButton
    private lateinit var btnLunchEnd: MaterialButton
    private lateinit var btnClockOut: MaterialButton
    private lateinit var progressAction: ProgressBar
    private lateinit var tvActionMsg: TextView
    private lateinit var tvWeekHours: TextView
    private lateinit var historyContainer: LinearLayout
    private lateinit var tvHistoryEmpty: TextView

    private val timerHandler = Handler(Looper.getMainLooper())
    private var elapsedBase = 0L
    private var timerRunning = false

    private val timerTick = object : Runnable {
        override fun run() {
            if (!timerRunning) return
            elapsedBase++
            tvTimer.text = formatElapsed(elapsedBase)
            timerHandler.postDelayed(this, 1000)
        }
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View =
        inflater.inflate(R.layout.fragment_clock, container, false)

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        prefs = PrefsManager(requireContext())
        api   = ApiClient(prefs.getInstanceUrl())

        swipeRefresh    = view.findViewById(R.id.swipeRefresh)
        statusCard      = view.findViewById(R.id.statusCard)
        tvGreeting      = view.findViewById(R.id.tvGreeting)
        tvName          = view.findViewById(R.id.tvName)
        tvStatusBadge   = view.findViewById(R.id.tvStatusBadge)
        tvTimer         = view.findViewById(R.id.tvTimer)
        tvClockInTime   = view.findViewById(R.id.tvClockInTime)
        etNote          = view.findViewById(R.id.etNote)
        btnClockIn      = view.findViewById(R.id.btnClockIn)
        btnLunchStart   = view.findViewById(R.id.btnLunchStart)
        btnLunchEnd     = view.findViewById(R.id.btnLunchEnd)
        btnClockOut     = view.findViewById(R.id.btnClockOut)
        progressAction  = view.findViewById(R.id.progressAction)
        tvActionMsg     = view.findViewById(R.id.tvActionMsg)
        tvWeekHours     = view.findViewById(R.id.tvWeekHours)
        historyContainer= view.findViewById(R.id.historyContainer)
        tvHistoryEmpty  = view.findViewById(R.id.tvHistoryEmpty)

        tvGreeting.text = greeting()

        swipeRefresh.setColorSchemeResources(R.color.colorPrimary)
        swipeRefresh.setOnRefreshListener { loadAll() }

        setupPressEffects()
        btnClockIn.setOnClickListener    { submitPunch("clockin") }
        btnLunchStart.setOnClickListener { submitPunch("lunchstart") }
        btnLunchEnd.setOnClickListener   { submitPunch("lunchend") }
        btnClockOut.setOnClickListener   { submitPunch("clockout") }

        loadAll()
    }

    override fun onResume() {
        super.onResume()
        if (prefs.getAccessToken().isNotEmpty()) loadAll()
    }

    override fun onPause() {
        super.onPause()
        stopTimer()
    }

    private fun setupPressEffects() {
        listOf(btnClockIn, btnLunchStart, btnLunchEnd, btnClockOut).forEach { btn ->
            btn.setOnTouchListener { v, event ->
                when (event.action) {
                    MotionEvent.ACTION_DOWN ->
                        v.animate().scaleX(0.94f).scaleY(0.94f).setDuration(80).start()
                    MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL ->
                        v.animate().scaleX(1f).scaleY(1f).setDuration(120).start()
                }
                false
            }
        }
    }

    private fun greeting(): String {
        val h = java.util.Calendar.getInstance().get(java.util.Calendar.HOUR_OF_DAY)
        return when {
            h < 12 -> "Good morning"
            h < 17 -> "Good afternoon"
            else   -> "Good evening"
        }
    }

    private fun loadAll() {
        if (prefs.isTokenExpired()) { refreshThenLoad(); return }
        loadStatus()
        loadHistory()
    }

    private fun refreshThenLoad() {
        Thread {
            val r = api.refreshToken(prefs.getRefreshToken())
            activity?.runOnUiThread {
                if (r.success) { prefs.saveTokens(r.accessToken, r.refreshToken, r.expiresIn); loadAll() }
            }
        }.start()
    }

    private fun loadStatus() {
        Thread {
            val s = api.getPunchStatus(prefs.getAccessToken())
            activity?.runOnUiThread {
                swipeRefresh.isRefreshing = false
                if (s.success) applyStatus(s)
            }
        }.start()
    }

    private fun loadHistory() {
        Thread {
            val h = api.getPunchHistory(prefs.getAccessToken())
            activity?.runOnUiThread {
                if (h.success) {
                    tvWeekHours.text = "${h.totalHours} hrs this week"
                    renderHistory(h.punches)
                }
            }
        }.start()
    }

    private fun applyStatus(s: PunchStatus) {
        tvName.text = s.name

        // Swap drawable per status — no runtime gradient mutation
        val (bgRes, badgeText) = when (s.status) {
            "In"    -> Pair(R.drawable.status_card_in,    "● CLOCKED IN")
            "Lunch" -> Pair(R.drawable.status_card_lunch, "● ON LUNCH")
            else    -> Pair(R.drawable.status_card_bg,    "● CLOCKED OUT")
        }
        statusCard.setBackgroundResource(bgRes)
        tvStatusBadge.text = badgeText

        if (s.status != "Out" && s.clockInTime != null) {
            tvClockInTime.text = "Since ${s.clockInTime}"
            tvClockInTime.visibility = View.VISIBLE
            tvTimer.visibility = View.VISIBLE
            elapsedBase = s.elapsedSeconds
            tvTimer.text = formatElapsed(elapsedBase)
            if (s.status == "In") startTimer() else stopTimer()
        } else {
            tvClockInTime.visibility = View.GONE
            tvTimer.visibility = View.GONE
            stopTimer()
        }

        val isOut   = s.status == "Out"
        val isIn    = s.status == "In"
        val isLunch = s.status == "Lunch"
        setEnabled(btnClockIn,    isOut,          R.color.colorClockIn)
        setEnabled(btnLunchStart, isIn,           R.color.colorLunchStart)
        setEnabled(btnLunchEnd,   isLunch,        R.color.colorLunchEnd)
        setEnabled(btnClockOut,   isIn||isLunch,  R.color.colorClockOut)
    }

    private fun setEnabled(btn: MaterialButton, enabled: Boolean, colorRes: Int) {
        btn.isEnabled = enabled
        btn.alpha = if (enabled) 1.0f else 0.35f
        btn.backgroundTintList = ContextCompat.getColorStateList(requireContext(), colorRes)
    }

    private fun submitPunch(action: String) {
        val note = etNote.text?.toString()?.trim() ?: ""
        setActionsLoading(true)
        tvActionMsg.visibility = View.GONE
        Thread {
            val r = api.punch(prefs.getAccessToken(), action, note)
            activity?.runOnUiThread {
                setActionsLoading(false)
                etNote.setText("")
                showMsg(if (r.success) r.message else r.error, r.success)
                if (r.success) loadAll()
            }
        }.start()
    }

    private fun setActionsLoading(on: Boolean) {
        progressAction.visibility = if (on) View.VISIBLE else View.GONE
        listOf(btnClockIn, btnLunchStart, btnLunchEnd, btnClockOut).forEach { it.isEnabled = !on }
    }

    private fun showMsg(msg: String, success: Boolean) {
        if (msg.isEmpty()) return
        tvActionMsg.text = msg.removePrefix("✅ ").removePrefix("🕔 ").removePrefix("🍽️ ")
        tvActionMsg.setTextColor(ContextCompat.getColor(requireContext(),
            if (success) R.color.colorStatusIn else R.color.colorError))
        tvActionMsg.visibility = View.VISIBLE
        tvActionMsg.startAnimation(AnimationUtils.loadAnimation(requireContext(), R.anim.fade_in))
    }

    private fun renderHistory(punches: List<PunchEntry>) {
        historyContainer.removeAllViews()
        if (punches.isEmpty()) { tvHistoryEmpty.visibility = View.VISIBLE; return }
        tvHistoryEmpty.visibility = View.GONE

        punches.take(7).forEachIndexed { i, entry ->
            val row = layoutInflater.inflate(R.layout.item_punch_row, historyContainer, false)
            row.findViewById<TextView>(R.id.tvDate).text = entry.date
            row.findViewById<TextView>(R.id.tvInOut).text =
                "${entry.inTime ?: "—"}  →  ${entry.outTime ?: "still open"}"
            val lunchTv = row.findViewById<TextView>(R.id.tvLunch)
            if (entry.lunchStart != null) {
                lunchTv.text = "Lunch: ${entry.lunchStart} – ${entry.lunchEnd ?: "in progress"}"
                lunchTv.visibility = View.VISIBLE
            }
            val hrsTv = row.findViewById<TextView>(R.id.tvHours)
            if (entry.hours != null) { hrsTv.text = "${entry.hours}h"; hrsTv.visibility = View.VISIBLE }
            else hrsTv.visibility = View.GONE

            historyContainer.addView(row)
            if (i < punches.size - 1) {
                historyContainer.addView(View(requireContext()).apply {
                    layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 1)
                    setBackgroundColor(ContextCompat.getColor(requireContext(), R.color.colorDivider))
                })
            }
            row.startAnimation(AnimationUtils.loadAnimation(requireContext(), R.anim.fade_in).apply {
                startOffset = (i * 30).toLong()
            })
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

    private fun formatElapsed(s: Long) = "%02d:%02d:%02d".format(s / 3600, (s % 3600) / 60, s % 60)
}
