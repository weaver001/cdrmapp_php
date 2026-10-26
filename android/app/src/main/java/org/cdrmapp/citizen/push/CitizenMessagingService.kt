package org.cdrmapp.citizen.push
import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import org.cdrmapp.citizen.BuildConfig
import org.cdrmapp.citizen.R
import org.cdrmapp.citizen.MainActivity
import org.cdrmapp.citizen.data.*
enum class PushRegistrationState { Idle, Registering, Registered, Failed, NotConfigured }
object PushRegistration {
 private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
 private val mutable = MutableStateFlow(PushRegistrationState.Idle)
 val state = mutable.asStateFlow()
 private fun failed(stage: String, error: Exception) {
  mutable.value = PushRegistrationState.Failed
  if (BuildConfig.DEBUG) Log.w("CDRMAPP_PUSH", "$stage failed: ${error.javaClass.simpleName}")
 }
 fun register(context: Context) {
  if (FirebaseApp.getApps(context).isEmpty()) { mutable.value = PushRegistrationState.NotConfigured; return }
  mutable.value = PushRegistrationState.Registering
  FirebaseMessaging.getInstance().token.addOnSuccessListener { registerToken(context.applicationContext, it) }.addOnFailureListener { failed("Firebase token retrieval", it) }
 }
 fun registerToken(context: Context, token: String) {
  scope.launch {
   try {
    val store=SecureStore(context.applicationContext)
    store.put("push_token",token)
    val repo = Repository(store)
    if (!repo.hasSession()) { mutable.value = PushRegistrationState.Idle; return@launch }
    repo.api.device(mapOf("token" to token))
    mutable.value = PushRegistrationState.Registered
   } catch (e: CancellationException) { throw e } catch (e: Exception) { failed("Device registration", e) }
  }
 }
}
class CitizenMessagingService : FirebaseMessagingService() {
 override fun onNewToken(token: String) { PushRegistration.registerToken(this, token) }
 override fun onMessageReceived(message: RemoteMessage) {
  if (android.os.Build.VERSION.SDK_INT >= 33 && ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) return
  val manager = getSystemService(NotificationManager::class.java)
  manager.createNotificationChannel(NotificationChannel("incident-status", "Incident status updates", NotificationManager.IMPORTANCE_DEFAULT))
  val intent = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
  val notification = NotificationCompat.Builder(this, "incident-status").setSmallIcon(R.drawable.ic_stat_cdrmapp).setContentTitle("CDRMAPP status update").setContentText("Open the application to view your latest incident status.").setContentIntent(intent).setAutoCancel(true).setVisibility(NotificationCompat.VISIBILITY_PRIVATE).build()
  manager.notify(message.data["incident_id"]?.toIntOrNull() ?: 0, notification)
 }
}
