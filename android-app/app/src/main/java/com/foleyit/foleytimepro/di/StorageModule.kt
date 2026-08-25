package com.foleyit.foleytimepro.di

import android.content.Context
import androidx.room.Room
import com.foleyit.foleytimepro.data.local.AppDatabase
import com.foleyit.foleytimepro.data.local.dao.CachedPunchDao
import com.foleyit.foleytimepro.data.local.dao.CachedShiftDao
import com.foleyit.foleytimepro.data.local.dao.QueuedPunchDao
import com.foleyit.foleytimepro.utils.PrefsManager
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

/**
 * Bindings for on-device persistence: encrypted key-value + Room.
 */
@Module
@InstallIn(SingletonComponent::class)
object StorageModule {

    @Provides @Singleton
    fun providePrefs(@ApplicationContext context: Context): PrefsManager = PrefsManager(context)

    @Provides @Singleton
    fun provideAppDatabase(@ApplicationContext context: Context): AppDatabase =
        Room.databaseBuilder(context, AppDatabase::class.java, AppDatabase.NAME)
            .fallbackToDestructiveMigration()
            .build()

    @Provides fun provideQueuedPunchDao(db: AppDatabase): QueuedPunchDao = db.queuedPunchDao()
    @Provides fun provideCachedPunchDao(db: AppDatabase): CachedPunchDao = db.cachedPunchDao()
    @Provides fun provideCachedShiftDao(db: AppDatabase): CachedShiftDao = db.cachedShiftDao()
}
