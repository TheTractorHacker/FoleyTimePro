package com.foleyit.foleytimepro.data.local.dao

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.foleyit.foleytimepro.data.local.entity.CachedShiftEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface CachedShiftDao {

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsert(entity: CachedShiftEntity)

    @Query("SELECT * FROM cached_shifts WHERE userId = :userId LIMIT 1")
    fun observeForUser(userId: Int): Flow<CachedShiftEntity?>

    @Query("SELECT * FROM cached_shifts WHERE userId = :userId LIMIT 1")
    suspend fun snapshotForUser(userId: Int): CachedShiftEntity?

    @Query("DELETE FROM cached_shifts WHERE userId = :userId")
    suspend fun deleteForUser(userId: Int)

    @Query("DELETE FROM cached_shifts")
    suspend fun clear()
}
