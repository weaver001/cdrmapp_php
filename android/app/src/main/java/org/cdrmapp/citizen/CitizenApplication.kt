package org.cdrmapp.citizen
import android.app.Application
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
class CitizenApplication : Application() {
 override fun onCreate() {
  super.onCreate()
  if (BuildConfig.FIREBASE_APPLICATION_ID.isNotBlank() && BuildConfig.FIREBASE_API_KEY.isNotBlank() && BuildConfig.FIREBASE_PROJECT_ID.isNotBlank() && BuildConfig.FIREBASE_SENDER_ID.isNotBlank()) {
   FirebaseApp.initializeApp(this, FirebaseOptions.Builder().setApplicationId(BuildConfig.FIREBASE_APPLICATION_ID).setApiKey(BuildConfig.FIREBASE_API_KEY).setProjectId(BuildConfig.FIREBASE_PROJECT_ID).setGcmSenderId(BuildConfig.FIREBASE_SENDER_ID).build())
  }
 }
}
