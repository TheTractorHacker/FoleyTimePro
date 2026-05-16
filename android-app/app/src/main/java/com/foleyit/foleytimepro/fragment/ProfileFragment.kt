package com.foleyit.foleytimepro.fragment

import android.content.Intent
import android.os.Bundle
import android.view.*
import android.view.animation.AnimationUtils
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AlertDialog
import androidx.fragment.app.Fragment
import com.foleyit.foleytimepro.LoginActivity
import com.foleyit.foleytimepro.R
import com.foleyit.foleytimepro.SetupActivity
import com.foleyit.foleytimepro.utils.PrefsManager
import com.google.android.material.button.MaterialButton

class ProfileFragment : Fragment() {

    private lateinit var prefs: PrefsManager

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View =
        inflater.inflate(R.layout.fragment_profile, container, false)

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        prefs = PrefsManager(requireContext())

        val name    = prefs.getUserName()
        val initials = name.split(" ").filter { it.isNotEmpty() }
            .take(2).joinToString("") { it.first().uppercase() }

        view.findViewById<TextView>(R.id.tvInitials).text = initials
        view.findViewById<TextView>(R.id.tvFullName).text = name
        view.findViewById<TextView>(R.id.tvEmail).text    = prefs.getAccessToken().let {
            // Decode email from stored prefs
            requireContext().getSharedPreferences("foley_timepro", 0)
                .getString("user_email", "—") ?: "—"
        }
        view.findViewById<TextView>(R.id.tvServer).text   = prefs.getInstanceUrl()
            .removePrefix("https://").removePrefix("http://")
        view.findViewById<TextView>(R.id.tvCompany).text  = prefs.getCompanyName()

        view.findViewById<LinearLayout?>(R.id.rowChangeServer)?.setOnClickListener {
            prefs.clearAll()
            startActivity(Intent(requireContext(), SetupActivity::class.java))
            requireActivity().finish()
        }

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
}
