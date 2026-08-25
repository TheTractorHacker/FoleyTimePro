package com.foleyit.foleytimepro.data.local.entity

import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

/**
 * Read-through cache for punch history so the Timesheet screen can render
 * instantly from disk and refresh from network. Unique per (user, date).
 */
@Entity(
    tableName = "cached_punches",
    indices   = [Index(value = ["userId", "date"], unique = true)]
)
data class CachedPunchEntity(
    @PrimaryKey(autoGenerate = true) val rowId: Long = 0,
    val userId: Int,
    val date: String,
    val inTime: String?,
    val lunchStart: String?,
    val lunchEnd: String?,
    val outTime: String?,
    val hours: String?,
    val note: String,
    val source: String?,
    val cachedAtEpochMs: Long = System.currentTimeMillis(),
)
