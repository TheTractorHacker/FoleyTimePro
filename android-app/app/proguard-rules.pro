# ---- FoleyTimePro / R8 rules ----

# Preserve stack traces (unobfuscated line numbers for crash reports)
-keepattributes *Annotation*
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile

# Kotlin metadata
-dontwarn kotlin.**
-keep class kotlin.Metadata { *; }

# AndroidX (broadly kept — the compat surface is large)
-dontwarn androidx.**
-keep class androidx.** { *; }
-keep interface androidx.** { *; }

# ---- kotlinx.serialization ----
-keepattributes RuntimeVisibleAnnotations,AnnotationDefault,InnerClasses,Signature,EnclosingMethod
-keepclassmembers class kotlinx.serialization.json.** {
    *** Companion;
}
-keepclasseswithmembers class kotlinx.serialization.json.** {
    kotlinx.serialization.KSerializer serializer(...);
}
-keep,includedescriptorclasses class com.foleyit.foleytimepro.data.remote.dto.**$$serializer { *; }
-keepclassmembers class com.foleyit.foleytimepro.data.remote.dto.** {
    *** Companion;
    kotlinx.serialization.KSerializer serializer(...);
}
-keep class com.foleyit.foleytimepro.data.remote.dto.** { *; }

# ---- Hilt / Dagger ----
-keep class dagger.hilt.** { *; }
-keep,allowobfuscation class * extends androidx.lifecycle.ViewModel
-keepclassmembers class * extends androidx.lifecycle.ViewModel {
    <init>(...);
}

# ---- Room ----
-keep class * extends androidx.room.RoomDatabase
-keep @androidx.room.Entity class *
-keep @androidx.room.Dao class *
-dontwarn androidx.room.paging.**

# ---- WorkManager ----
-keep class * extends androidx.work.Worker
-keep class * extends androidx.work.CoroutineWorker
-keep class * extends androidx.work.ListenableWorker {
    public <init>(android.content.Context, androidx.work.WorkerParameters);
}

# ---- OkHttp ----
-dontwarn okhttp3.internal.platform.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# ---- Firebase Cloud Messaging ----
-keep class com.google.firebase.messaging.** { *; }
-dontwarn com.google.firebase.**

# ---- App entry ----
-keep public class com.foleyit.foleytimepro.FoleyApp { *; }
-keep public class com.foleyit.foleytimepro.MainActivity { *; }
