package com.foleyit.foleytimepro

import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.fragment.app.Fragment
import com.foleyit.foleytimepro.fragment.*
import com.google.android.material.bottomnavigation.BottomNavigationView

class DashboardActivity : AppCompatActivity() {

    private lateinit var bottomNav: BottomNavigationView
    private var currentTabId = R.id.nav_clock

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_dashboard)

        bottomNav = findViewById(R.id.bottomNav)

        if (savedInstanceState == null) {
            loadFragment(ClockFragment(), animate = false)
        }

        bottomNav.setOnItemSelectedListener { item ->
            if (item.itemId == currentTabId) return@setOnItemSelectedListener false
            val fragment = when (item.itemId) {
                R.id.nav_clock     -> ClockFragment()
                R.id.nav_timesheet -> TimesheetFragment()
                R.id.nav_timeoff   -> TimeOffFragment()
                R.id.nav_schedule  -> ScheduleFragment()
                R.id.nav_profile   -> ProfileFragment()
                else               -> ClockFragment()
            }
            // Slide direction: right if moving to higher index, left if lower
            val tabs = listOf(R.id.nav_clock, R.id.nav_timesheet, R.id.nav_timeoff,
                              R.id.nav_schedule, R.id.nav_profile)
            val goingRight = tabs.indexOf(item.itemId) > tabs.indexOf(currentTabId)
            currentTabId = item.itemId
            loadFragment(fragment, animate = true, goingRight = goingRight)
            true
        }
    }

    private fun loadFragment(fragment: Fragment, animate: Boolean = true, goingRight: Boolean = true) {
        val tx = supportFragmentManager.beginTransaction()
        if (animate) {
            if (goingRight) {
                tx.setCustomAnimations(R.anim.slide_in_right, R.anim.slide_out_left,
                                       R.anim.slide_in_left, R.anim.slide_out_right)
            } else {
                tx.setCustomAnimations(R.anim.slide_in_left, R.anim.slide_out_right,
                                       R.anim.slide_in_right, R.anim.slide_out_left)
            }
        }
        tx.replace(R.id.fragmentContainer, fragment).commit()
    }
}
