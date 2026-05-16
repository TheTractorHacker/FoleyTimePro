package com.foleyit.foleytimepro.fragment

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.*
import android.view.animation.AnimationUtils
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AlertDialog
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import com.foleyit.foleytimepro.LoginActivity
import com.foleyit.foleytimepro.R
import com.foleyit.foleytimepro.SetupActivity
import com.foleyit.foleytimepro.network.ApiClient
import com.foleyit.foleytimepro.utils.PrefsManager
import com.google.android.material.button.MaterialButton
import com.google.android.material.textfield.TextInputEditText

class ProfileFragment : Fragment() {

    private lateinit var prefs: PrefsManager
    private lateinit var api: ApiClient

    override fun onCreateView(i: LayoutInflater, c: ViewGroup?, s: Bundle?): View =
        i.inflate(R.layout.fragment_profile, c, false)

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        prefs = PrefsManager(requireContext())
        api   = ApiClient(prefs.getInstanceUrl())

        val name     = prefs.getUserName()
        val initials = name.split(" ").filter { it.isNotEmpty() }
            .take(2).joinToString("") { it.first().uppercase() }
        val email    = requireContext().getSharedPreferences("foley_timepro", 0)
            .getString("user_email", "") ?: ""

        view.findViewById<TextView>(R.id.tvInitials).text = initials
        view.findViewById<TextView>(R.id.tvFullName).text = name
        view.findViewById<TextView>(R.id.tvEmail).text    = email.ifEmpty { "No email on file" }
        view.findViewById<TextView>(R.id.tvServer).text   = prefs.getInstanceUrl()
            .removePrefix("https://").removePrefix("http://")
        view.findViewById<TextView>(R.id.tvCompany).text  = prefs.getCompanyName()

        // Change password
        val etNew     = view.findViewById<TextInputEditText>(R.id.etNewPassword)
        val etConfirm = view.findViewById<TextInputEditText>(R.id.etConfirmPassword)
        val tvMsg     = view.findViewById<TextView>(R.id.tvPasswordMsg)
        view.findViewById<MaterialButton>(R.id.btnChangePassword).setOnClickListener {
            val newPw  = etNew.text.toString()
            val confPw = etConfirm.text.toString()
            if (newPw.isEmpty()) {
                showMsg(tvMsg, "Enter a new password", false); return@setOnClickListener
            }
            if (newPw != confPw) {
                showMsg(tvMsg, "Passwords don't match", false); return@setOnClickListener
            }
            if (newPw.length < 6) {
                showMsg(tvMsg, "Password must be at least 6 characters", false); return@setOnClickListener
            }
            view.findViewById<MaterialButton>(R.id.btnChangePassword).isEnabled = false
            Thread {
                val result = api.changePassword(prefs.getAccessToken(), newPw)
                activity?.runOnUiThread {
                    view.findViewById<MaterialButton>(R.id.btnChangePassword).isEnabled = true
                    showMsg(tvMsg, if (result.success) "Password updated!" else result.error, result.success)
                    if (result.success) { etNew.setText(""); etConfirm.setText("") }
                }
            }.start()
        }

        // Change server
        view.findViewById<LinearLayout>(R.id.rowChangeServer).setOnClickListener {
            prefs.clearAll()
            startActivity(Intent(requireContext(), SetupActivity::class.java))
            requireActivity().finish()
        }

        // 2FA → open web portal settings
        view.findViewById<LinearLayout>(R.id.row2FA).setOnClickListener {
            val url = "${prefs.getInstanceUrl()}/user/settings.php"
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
        }

        // Logout
        view.findViewById<MaterialButton>(R.id.btnLogout).setOnClickListener {
            AlertDialog.Builder(requireContext())
                .setTitle("Sign Out")
                .setMessage("Are you sure you want to sign out?")
                .setPositiveButton("Sign Out") { _, _ ->
                    prefs.clearSession()
                    startActivity(Intent(requireContext(), LoginActivity::class.java)
                        .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_NEW_TASK))
                    requireActivity().finish()
                }
                .setNegativeButton("Cancel", null)
                .show()
        }

        view.startAnimation(AnimationUtils.loadAnimation(requireContext(), R.anim.fade_in))
    }

    private fun showMsg(tv: TextView, msg: String, success: Boolean) {
        tv.text = msg
        tv.setTextColor(ContextCompat.getColor(requireContext(),
            if (success) R.color.colorStatusIn else R.color.colorError))
        tv.visibility = View.VISIBLE
        tv.startAnimation(AnimationUtils.loadAnimation(requireContext(), R.anim.fade_in))
    }
}
