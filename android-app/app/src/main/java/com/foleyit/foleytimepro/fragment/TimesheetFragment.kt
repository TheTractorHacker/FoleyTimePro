package com.foleyit.foleytimepro.fragment

import android.os.Bundle
import android.view.*
import android.view.animation.AnimationUtils
import android.widget.*
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import com.foleyit.foleytimepro.R
import com.foleyit.foleytimepro.network.ApiClient
import com.foleyit.foleytimepro.network.PunchEntry
import com.foleyit.foleytimepro.utils.PrefsManager
import com.google.android.material.button.MaterialButton
import com.google.android.material.chip.Chip
import com.google.android.material.chip.ChipGroup
import java.text.SimpleDateFormat
import java.util.*

class TimesheetFragment : Fragment() {

    private lateinit var prefs: PrefsManager
    private lateinit var api: ApiClient

    private lateinit var swipeRefresh: SwipeRefreshLayout
    private lateinit var btnPrev: MaterialButton
    private lateinit var btnNext: MaterialButton
    private lateinit var tvWeekRange: TextView
    private lateinit var tvWeekTotal: TextView
    private lateinit var chipGroup: ChipGroup
    private lateinit var punchListContainer: LinearLayout
    private lateinit var tvEmpty: TextView

    private val sdf  = SimpleDateFormat("yyyy-MM-dd", Locale.US)
    private val disp = SimpleDateFormat("MMM d", Locale.US)
    private val full = SimpleDateFormat("MMM d, yyyy", Locale.US)

    // Track week offset from current (0 = this week, -1 = last week, etc.)
    private var weekOffset = 0

    override fun onCreateView(i: LayoutInflater, c: ViewGroup?, s: Bundle?): View =
        i.inflate(R.layout.fragment_timesheet, c, false)

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        prefs = PrefsManager(requireContext())
        api   = ApiClient(prefs.getInstanceUrl())

        swipeRefresh       = view.findViewById(R.id.swipeRefresh)
        btnPrev            = view.findViewById(R.id.btnPrevWeek)
        btnNext            = view.findViewById(R.id.btnNextWeek)
        tvWeekRange        = view.findViewById(R.id.tvWeekRange)
        tvWeekTotal        = view.findViewById(R.id.tvWeekTotal)
        chipGroup          = view.findViewById(R.id.chipGroupDays)
        punchListContainer = view.findViewById(R.id.punchListContainer)
        tvEmpty            = view.findViewById(R.id.tvEmpty)

        swipeRefresh.setColorSchemeResources(R.color.colorPrimary)
        swipeRefresh.setOnRefreshListener { loadWeek() }

        btnPrev.setOnClickListener {
            weekOffset--
            btnNext.isEnabled = weekOffset < 0
            loadWeek()
        }
        btnNext.setOnClickListener {
            if (weekOffset < 0) { weekOffset++; loadWeek() }
            btnNext.isEnabled = weekOffset < 0
        }
        btnNext.isEnabled = false // can't go past current week

        loadWeek()
    }

    private fun weekDates(): Pair<String, String> {
        val cal = Calendar.getInstance()
        cal.add(Calendar.WEEK_OF_YEAR, weekOffset)
        cal.set(Calendar.DAY_OF_WEEK, Calendar.MONDAY)
        val start = sdf.format(cal.time)
        val endCal = cal.clone() as Calendar
        endCal.add(Calendar.DAY_OF_MONTH, 6)
        val end = sdf.format(endCal.time)
        tvWeekRange.text = if (weekOffset == 0) "This Week  •  ${disp.format(cal.time)}–${disp.format(endCal.time)}"
                           else "${disp.format(cal.time)} – ${full.format(endCal.time)}"
        return Pair(start, end)
    }

    private fun loadWeek() {
        val (start, end) = weekDates()
        Thread {
            val h = api.getPunchHistory(prefs.getAccessToken())
            // Filter client-side by the selected week since API always returns current week
            // For past weeks we need the start param — pass it via query but API accepts it
            activity?.runOnUiThread {
                swipeRefresh.isRefreshing = false
                if (h.success) renderPunches(h.punches, h.totalHours)
                else { tvEmpty.visibility = View.VISIBLE; tvEmpty.text = "Could not load timesheet" }
            }
        }.start()
    }

    private fun renderPunches(punches: List<PunchEntry>, total: String) {
        tvWeekTotal.text = "$total hrs"
        chipGroup.removeAllViews()
        punchListContainer.removeAllViews()

        val dayLabels = listOf("Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun")
        // Mark which days have punches
        val punchedDays = punches.map {
            try { SimpleDateFormat("EEE", Locale.US).format(SimpleDateFormat("MM/dd/yyyy", Locale.US).parse(it.date)!!) }
            catch (e: Exception) { "" }
        }.toSet()

        dayLabels.forEach { day ->
            val hasWork = punchedDays.any { it.equals(day, ignoreCase = true) }
            val chip = Chip(requireContext()).apply {
                text = day
                isCheckable = false
                chipBackgroundColor = ContextCompat.getColorStateList(requireContext(),
                    if (hasWork) R.color.colorPrimary else android.R.color.darker_gray)
                setTextColor(ContextCompat.getColor(requireContext(), android.R.color.white))
                textSize = 11f
            }
            chipGroup.addView(chip)
        }

        if (punches.isEmpty()) {
            tvEmpty.visibility = View.VISIBLE
            tvEmpty.text = "No punches for this period"
            return
        }
        tvEmpty.visibility = View.GONE

        punches.forEachIndexed { i, entry ->
            val wrapper = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.VERTICAL
                setPadding(0, 14, 0, 14)
            }

            // Date + hours row
            val topRow = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = android.view.Gravity.CENTER_VERTICAL
            }
            topRow.addView(TextView(requireContext()).apply {
                text = entry.date
                textSize = 14f
                typeface = android.graphics.Typeface.DEFAULT_BOLD
                setTextColor(ContextCompat.getColor(requireContext(), R.color.colorOnSurface))
                layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f)
            })
            topRow.addView(TextView(requireContext()).apply {
                text = if (entry.hours != null) "${entry.hours} hrs" else "open"
                textSize = 13f
                typeface = android.graphics.Typeface.DEFAULT_BOLD
                setTextColor(ContextCompat.getColor(requireContext(),
                    if (entry.outTime != null) R.color.colorPrimary else R.color.colorStatusIn))
            })

            // In/Out row
            val ioRow = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.HORIZONTAL
                setPadding(0, 4, 0, 0)
            }
            ioRow.addView(mkLabel("In: ${entry.inTime ?: "—"}"))
            ioRow.addView(mkSpacer())
            ioRow.addView(mkLabel("Out: ${entry.outTime ?: "—"}"))

            wrapper.addView(topRow)
            wrapper.addView(ioRow)

            // Lunch row
            if (entry.lunchStart != null) {
                wrapper.addView(TextView(requireContext()).apply {
                    text = "Lunch: ${entry.lunchStart} – ${entry.lunchEnd ?: "in progress"}"
                    textSize = 12f
                    setTextColor(ContextCompat.getColor(requireContext(), R.color.colorTextSecondary))
                    setPadding(0, 2, 0, 0)
                })
            }

            // Note row
            if (!entry.note.isNullOrBlank()) {
                wrapper.addView(TextView(requireContext()).apply {
                    text = "Note: ${entry.note}"
                    textSize = 11f
                    setTextColor(ContextCompat.getColor(requireContext(), R.color.colorTextSecondary))
                    setPadding(0, 2, 0, 0)
                })
            }

            if (i < punches.size - 1) {
                wrapper.addView(View(requireContext()).apply {
                    layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 1).apply { topMargin = 8 }
                    setBackgroundColor(ContextCompat.getColor(requireContext(), R.color.colorDivider))
                })
            }

            punchListContainer.addView(wrapper)
            wrapper.startAnimation(AnimationUtils.loadAnimation(requireContext(), R.anim.fade_in).apply {
                startOffset = (i * 30).toLong()
            })
        }
    }

    private fun mkLabel(text: String) = TextView(requireContext()).apply {
        this.text = text; textSize = 12f
        setTextColor(ContextCompat.getColor(requireContext(), R.color.colorTextSecondary))
    }

    private fun mkSpacer() = View(requireContext()).apply {
        layoutParams = LinearLayout.LayoutParams(0, 1, 1f)
    }
}
