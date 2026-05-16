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

    private val sdf = SimpleDateFormat("yyyy-MM-dd", Locale.US)
    private val cal = Calendar.getInstance()

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View =
        inflater.inflate(R.layout.fragment_timesheet, container, false)

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        prefs = PrefsManager(requireContext())
        api   = ApiClient(prefs.getInstanceUrl())

        swipeRefresh      = view.findViewById(R.id.swipeRefresh)
        btnPrev           = view.findViewById(R.id.btnPrevWeek)
        btnNext           = view.findViewById(R.id.btnNextWeek)
        tvWeekRange       = view.findViewById(R.id.tvWeekRange)
        tvWeekTotal       = view.findViewById(R.id.tvWeekTotal)
        chipGroup         = view.findViewById(R.id.chipGroupDays)
        punchListContainer= view.findViewById(R.id.punchListContainer)
        tvEmpty           = view.findViewById(R.id.tvEmpty)

        // Start on current week (Monday)
        cal.set(Calendar.DAY_OF_WEEK, Calendar.MONDAY)

        swipeRefresh.setColorSchemeResources(R.color.colorPrimary)
        swipeRefresh.setOnRefreshListener { loadWeek() }
        btnPrev.setOnClickListener { cal.add(Calendar.WEEK_OF_YEAR, -1); loadWeek() }
        btnNext.setOnClickListener { cal.add(Calendar.WEEK_OF_YEAR, 1); loadWeek() }

        view.startAnimation(AnimationUtils.loadAnimation(requireContext(), R.anim.fade_in))
        loadWeek()
    }

    private fun loadWeek() {
        val start = sdf.format(cal.time)
        val endCal = cal.clone() as Calendar
        endCal.add(Calendar.DAY_OF_WEEK, 4) // Friday
        val end = sdf.format(endCal.time)

        val disp = SimpleDateFormat("MMM d", Locale.US)
        val dispEnd = SimpleDateFormat("MMM d, yyyy", Locale.US)
        tvWeekRange.text = "${disp.format(cal.time)} – ${dispEnd.format(endCal.time)}"

        Thread {
            val h = api.getPunchHistory(prefs.getAccessToken())
            activity?.runOnUiThread {
                swipeRefresh.isRefreshing = false
                if (h.success) renderPunches(h.punches, h.totalHours)
            }
        }.start()
    }

    private fun renderPunches(punches: List<PunchEntry>, total: String) {
        tvWeekTotal.text = "$total hrs total"
        chipGroup.removeAllViews()
        punchListContainer.removeAllViews()

        val days = listOf("Mon", "Tue", "Wed", "Thu", "Fri")
        days.forEachIndexed { i, day ->
            val chip = Chip(requireContext()).apply {
                text = day; isCheckable = false
                chipBackgroundColor = ContextCompat.getColorStateList(requireContext(),
                    if (i < punches.size) R.color.colorPrimary else android.R.color.darker_gray)
                setTextColor(ContextCompat.getColor(requireContext(), android.R.color.white))
                textSize = 11f
            }
            chipGroup.addView(chip)
        }

        if (punches.isEmpty()) { tvEmpty.visibility = View.VISIBLE; return }
        tvEmpty.visibility = View.GONE

        punches.forEachIndexed { i, entry ->
            val card = layoutInflater.inflate(android.R.layout.two_line_list_item, punchListContainer, false)
            val t1 = card.findViewById<TextView>(android.R.id.text1)
            val t2 = card.findViewById<TextView>(android.R.id.text2)

            t1.text = "${entry.date}   ${entry.inTime ?: "—"} → ${entry.outTime ?: "still open"}"
            t1.textSize = 14f
            t1.setTextColor(ContextCompat.getColor(requireContext(), R.color.colorOnSurface))
            t1.typeface = android.graphics.Typeface.DEFAULT_BOLD

            val parts = mutableListOf<String>()
            if (entry.lunchStart != null) parts.add("Lunch ${entry.lunchStart}–${entry.lunchEnd ?: "?"}")
            if (entry.hours != null) parts.add("${entry.hours} hrs worked")
            t2.text = parts.joinToString("   ")
            t2.textSize = 12f
            t2.setTextColor(ContextCompat.getColor(requireContext(), R.color.colorTextSecondary))

            punchListContainer.addView(card)

            if (i < punches.size - 1) {
                val div = View(requireContext()).apply {
                    layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 1).apply { setMargins(0, 4, 0, 4) }
                    setBackgroundColor(ContextCompat.getColor(requireContext(), R.color.colorDivider))
                }
                punchListContainer.addView(div)
            }
            card.startAnimation(AnimationUtils.loadAnimation(requireContext(), R.anim.fade_in).apply {
                startOffset = (i * 80).toLong()
            })
        }
    }
}
