package com.foleyit.foleytimepro.data.local

import androidx.room.Database
import androidx.room.RoomDatabase
import com.foleyit.foleytimepro.data.local.dao.CachedPunchDao
import com.foleyit.foleytimepro.data.local.dao.CachedShiftDao
import com.foleyit.foleytimepro.data.local.dao.QueuedPunchDao
import com.foleyit.foleytimepro.data.local.entity.CachedPunchEntity
import com.foleyit.foleytimepro.data.local.entity.CachedShiftEntity
import com.foleyit.foleytimepro.data.local.entity.QueuedPunchEntity

@Database(
    entities = [
        QueuedPunchEntity::class,
        CachedPunchEntity::class,
        CachedShiftEntity::class,
    ],
    version = 1,
    exportSchema = false,
)
abstract class AppDatabase : RoomDatabase() {
    abstract fun queuedPunchDao(): QueuedPunchDao
    abstract fun cachedPunchDao(): CachedPunchDao
    abstract fun cachedShiftDao(): CachedShiftDao

    companion object {
        const val NAME = "foley_timepro.db"
    }
}
