package com.foleyit.foleytimepro.data.local.dao

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.foleyit.foleytimepro.data.local.entity.QueuedPunchEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface QueuedPunchDao {

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(entity: QueuedPunchEntity): Long

    @Query("SELECT * FROM queued_punches ORDER BY enqueuedAtEpochMs ASC")
    suspend fun pending(): List<QueuedPunchEntity>

    @Query("SELECT COUNT(*) FROM queued_punches WHERE userId = :userId")
    fun countForUser(userId: Int): Flow<Int>

    @Query("UPDATE queued_punches SET attempts = attempts + 1, lastError = :err WHERE id = :id")
    suspend fun markFailure(id: Long, err: String)

    @Query("DELETE FROM queued_punches WHERE id = :id")
    suspend fun deleteById(id: Long)

    @Query("DELETE FROM queued_punches WHERE userId = :userId")
    suspend fun deleteForUser(userId: Int)

    @Query("DELETE FROM queued_punches")
    suspend fun clear()
}
