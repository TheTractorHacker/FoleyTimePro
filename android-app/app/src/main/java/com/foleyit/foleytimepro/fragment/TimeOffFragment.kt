package com.foleyit.foleytimepro.fragment

import android.app.AlertDialog
import android.os.Bundle
import android.view.*
import android.view.View
import android.view.animation.AnimationUtils
import android.widget.*
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import com.foleyit.foleytimepro.R
import com.foleyit.foleytimepro.network.ApiClient
import com.foleyit.foleytimepro.network.TimeOffRequest
import com.foleyit.foleytimepro.utils.PrefsManager
import com.google.android.material.floatingactionbutton.FloatingActionButton
import com.google.android.material.textfield.TextInputEditText

class TimeOffFragment : Fragment() {

    private lateinit var prefs: PrefsManager
    private lateinit var api: ApiClient
    private lateinit var swipeRefresh: SwipeRefreshLayout
    private lateinit var requestsContainer: LinearLayout
    private lateinit var tvEmpty: TextView
    private lateinit var fab: FloatingActionButton

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View =
        inflater.inflate(R.layout.fragment_time_off, container, false)

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        prefs = PrefsManager(requireContext())
        api   = ApiClient(prefs.getInstanceUrl())

        swipeRefresh      = view.findViewById(R.id.swipeRefresh)
        requestsContainer = view.findViewById(R.id.requestsContainer)
        tvEmpty           = view.findViewById(R.id.tvEmpty)
        fab               = view.findViewById(R.id.fabRequest)

        swipeRefresh.setColorSchemeResources(R.color.colorPrimary)
        swipeRefresh.setOnRefreshListener { loadRequests() }
        fab.setOnClickListener { showRequestDialog() }

        view.startAnimation(AnimationUtils.loadAnimation(requireContext(), R.anim.fade_in))
        fab.startAnimation(AnimationUtils.loadAnimation(requireContext(), R.anim.slide_up))
        loadRequests()
    }

    private fun loadRequests() {
        Thread {
            val result = api.getTimeOffRequests(prefs.getAccessToken())
            activity?.runOnUiThread {
                swipeRefresh.isRefreshing = false
                if (result.success) renderRequests(result.requests)
                else { tvEmpty.visibility = View.VISIBLE; tvEmpty.text = result.error }
            }
        }.start()
    }

    private fun renderRequests(requests: List<TimeOffRequest>) {
        requestsContainer.removeAllViews()
        if (requests.isEmpty()) { tvEmpty.visibility = View.VISIBLE; return }
        tvEmpty.visibility = View.GONE

        requests.forEachIndexed { i, req ->
            val row = layoutInflater.inflate(R.layout.item_timeoff_row, requestsContainer, false)
            row.findViewById<TextView>(R.id.tvDates).text = "${req.startDate}  –  ${req.endDate}"
            row.findViewById<TextView>(R.id.tvType).text = req.type
            val reasonTv = row.findViewById<TextView>(R.id.tvReason)
            if (req.reason.isNotEmpty()) { reasonTv.text = req.reason; reasonTv.visibility = View.VISIBLE }

            val statusTv = row.findViewById<TextView>(R.id.tvStatus)
            statusTv.text = req.status
            val statusBg = statusTv.background as? android.graphics.drawable.GradientDrawable
            val statusColor = when (req.status) {
                "Approved" -> 0xFF16A34A.toInt()
                "Denied"   -> 0xFFDC2626.toInt()
                else       -> 0xFFF97316.toInt()
            }
            statusBg?.setColor(statusColor)

            requestsContainer.addView(row)
            if (i < requests.size - 1) {
                requestsContainer.addView(View(requireContext()).apply {
                    layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 1)
                    setBackgroundColor(ContextCompat.getColor(requireContext(), R.color.colorDivider))
                })
            }
            row.startAnimation(AnimationUtils.loadAnimation(requireContext(), R.anim.fade_in).apply {
                startOffset = (i * 25).toLong()
            })
        }
    }

    private fun showRequestDialog() {
        val ctx = requireContext()
        val dialogView = layoutInflater.inflate(R.layout.dialog_time_off_request, null)
        val etStart  = dialogView.findViewById<TextInputEditText>(R.id.etStartDate)
        val etEnd    = dialogView.findViewById<TextInputEditText>(R.id.etEndDate)
        val etReason = dialogView.findViewById<TextInputEditText>(R.id.etReason)

        val dialog = AlertDialog.Builder(ctx)
            .setView(dialogView)
            .create()
        dialog.window?.setBackgroundDrawableResource(android.R.color.transparent)

        dialogView.findViewById<com.google.android.material.button.MaterialButton>(R.id.btnCancel)
            .setOnClickListener { dialog.dismiss() }
        dialogView.findViewById<com.google.android.material.button.MaterialButton>(R.id.btnSubmit)
            .setOnClickListener {
                val start  = etStart.text.toString().trim()
                val end    = etEnd.text.toString().trim()
                val reason = etReason.text.toString().trim()
                if (start.isEmpty() || end.isEmpty()) {
                    Toast.makeText(ctx, "Please enter start and end dates", Toast.LENGTH_SHORT).show()
                    return@setOnClickListener
                }
                dialog.dismiss()
                Thread {
                    val r = api.submitTimeOffRequest(prefs.getAccessToken(), start, end, reason)
                    activity?.runOnUiThread {
                        Toast.makeText(ctx, if (r.success) r.message else r.error, Toast.LENGTH_LONG).show()
                        if (r.success) loadRequests()
                    }
                }.start()
            }
        dialog.show()
    }
}
