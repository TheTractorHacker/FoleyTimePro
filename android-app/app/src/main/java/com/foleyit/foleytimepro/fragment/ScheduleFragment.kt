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
import com.foleyit.foleytimepro.utils.PrefsManager
import java.text.SimpleDateFormat
import java.util.*

class ScheduleFragment : Fragment() {

    private lateinit var prefs: PrefsManager
    private lateinit var api: ApiClient
    private lateinit var swipeRefresh: SwipeRefreshLayout
    private lateinit var tvShiftName: TextView
    private lateinit var tvShiftTime: TextView
    private lateinit var shiftTimeRow: LinearLayout
    private lateinit var weekContainer: LinearLayout

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View =
        inflater.inflate(R.layout.fragment_schedule, container, false)

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        prefs = PrefsManager(requireContext())
        api   = ApiClient(prefs.getInstanceUrl())

        swipeRefresh = view.findViewById(R.id.swipeRefresh)
        tvShiftName  = view.findViewById(R.id.tvShiftName)
        tvShiftTime  = view.findViewById(R.id.tvShiftTime)
        shiftTimeRow = view.findViewById(R.id.shiftTimeRow)
        weekContainer= view.findViewById(R.id.weekContainer)

        swipeRefresh.setColorSchemeResources(R.color.colorPrimary)
        swipeRefresh.setOnRefreshListener { loadShift() }

        view.startAnimation(AnimationUtils.loadAnimation(requireContext(), R.anim.fade_in))
        loadShift()
    }

    private fun loadShift() {
        Thread {
            val shift = api.getMyShift(prefs.getAccessToken())
            activity?.runOnUiThread {
                swipeRefresh.isRefreshing = false
                if (shift.success && shift.shiftName.isNotEmpty()) {
                    tvShiftName.text = shift.shiftName
                    val start = fmt12(shift.startTime)
                    val end   = fmt12(shift.endTime)
                    tvShiftTime.text = "$start – $end"
                    shiftTimeRow.visibility = View.VISIBLE
                    renderWeek(shift.daysOfWeek, shift.startTime, shift.endTime)
                } else {
                    tvShiftName.text = "No shift assigned"
                    shiftTimeRow.visibility = View.GONE
                    renderWeek(emptyList(), "", "")
                }
            }
        }.start()
    }

    private fun renderWeek(workDays: List<String>, start: String, end: String) {
        weekContainer.removeAllViews()
        val allDays = listOf("Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun")
        val todayName = SimpleDateFormat("EEE", Locale.US).format(Date()).let {
            allDays.find { d -> d.equals(it, ignoreCase = true) } ?: ""
        }

        allDays.forEachIndexed { i, day ->
            val isWork  = workDays.any { it.contains(day, ignoreCase = true) }
            val isToday = day.equals(todayName, ignoreCase = true)

            val row = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.HORIZONTAL
                setPadding(0, 12, 0, 12)
                gravity = android.view.Gravity.CENTER_VERTICAL
            }

            // Day label
            val dayTv = TextView(requireContext()).apply {
                text = day
                textSize = 14f
                typeface = if (isToday) android.graphics.Typeface.DEFAULT_BOLD
                           else android.graphics.Typeface.DEFAULT
                setTextColor(ContextCompat.getColor(requireContext(),
                    if (isToday) R.color.colorPrimary else R.color.colorOnSurface))
                layoutParams = LinearLayout.LayoutParams(80, LinearLayout.LayoutParams.WRAP_CONTENT)
            }

            // Status bar
            val bar = View(requireContext()).apply {
                layoutParams = LinearLayout.LayoutParams(0, 4, 1f).apply { marginStart = 12; marginEnd = 12 }
                background = android.graphics.drawable.ColorDrawable(
                    ContextCompat.getColor(requireContext(),
                        when { isToday && isWork -> R.color.colorPrimary
                               isWork -> R.color.colorDivider
                               else -> android.R.color.transparent })
                )
            }

            // Time label
            val timeTv = TextView(requireContext()).apply {
                text = if (isWork && start.isNotEmpty()) "${fmt12(start)}–${fmt12(end)}" else "Off"
                textSize = 12f
                setTextColor(ContextCompat.getColor(requireContext(),
                    if (isWork) R.color.colorTextSecondary else R.color.colorTextHint))
                layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT,
                    LinearLayout.LayoutParams.WRAP_CONTENT)
            }

            row.addView(dayTv); row.addView(bar); row.addView(timeTv)

            if (i < allDays.size - 1) {
                val div = View(requireContext()).apply {
                    layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 1)
                    setBackgroundColor(ContextCompat.getColor(requireContext(), R.color.colorDivider))
                }
                weekContainer.addView(row); weekContainer.addView(div)
            } else {
                weekContainer.addView(row)
            }

            row.startAnimation(AnimationUtils.loadAnimation(requireContext(), R.anim.fade_in).apply {
                startOffset = (i * 25).toLong()
            })
        }
    }

    private fun fmt12(t: String): String {
        if (t.isEmpty()) return ""
        return try {
            val sdf = java.text.SimpleDateFormat("HH:mm:ss", Locale.US)
            val out = java.text.SimpleDateFormat("h:mm a", Locale.US)
            out.format(sdf.parse(t) ?: return t)
        } catch (e: Exception) { t }
    }
}
