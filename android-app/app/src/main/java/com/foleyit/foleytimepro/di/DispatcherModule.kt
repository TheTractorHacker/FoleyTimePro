package com.foleyit.foleytimepro.di

import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.Dispatchers
import javax.inject.Qualifier
import javax.inject.Singleton

@Qualifier @Retention(AnnotationRetention.BINARY) annotation class IoDispatcher
@Qualifier @Retention(AnnotationRetention.BINARY) annotation class DefaultDispatcher
@Qualifier @Retention(AnnotationRetention.BINARY) annotation class MainDispatcher

/**
 * Injectable coroutine dispatchers so tests can substitute `TestDispatcher`.
 */
@Module
@InstallIn(SingletonComponent::class)
object DispatcherModule {
    // `default` is a reserved word in Java; Hilt's annotation processor generates a
    // Java static proxy method with the same name as the Kotlin function and crashes
    // on it (javapoet: "not a valid name: default"). Hilt resolves providers by return
    // type + qualifier, not by function name, so renaming is safe.
    @Provides @Singleton @IoDispatcher      fun io():             CoroutineDispatcher = Dispatchers.IO
    @Provides @Singleton @DefaultDispatcher fun defaultDispatcher(): CoroutineDispatcher = Dispatchers.Default
    @Provides @Singleton @MainDispatcher    fun main():           CoroutineDispatcher = Dispatchers.Main
}
