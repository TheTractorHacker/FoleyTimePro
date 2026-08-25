package com.foleyit.foleytimepro.data.local.dao

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.foleyit.foleytimepro.data.local.entity.CachedPunchEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface CachedPunchDao {

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(entities: List<CachedPunchEntity>)

    @Query("SELECT * FROM cached_punches WHERE userId = :userId ORDER BY date DESC")
    fun observeForUser(userId: Int): Flow<List<CachedPunchEntity>>

    @Query("SELECT * FROM cached_punches WHERE userId = :userId ORDER BY date DESC")
    suspend fun snapshotForUser(userId: Int): List<CachedPunchEntity>

    @Query("DELETE FROM cached_punches WHERE userId = :userId")
    suspend fun deleteForUser(userId: Int)

    @Query("DELETE FROM cached_punches")
    suspend fun clear()
}
