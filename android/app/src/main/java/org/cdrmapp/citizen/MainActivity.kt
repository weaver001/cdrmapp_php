package org.cdrmapp.citizen

import android.Manifest
import android.content.pm.PackageManager
import android.os.Bundle
import android.os.SystemClock
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.repeatOnLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.maps.MapsInitializer
import com.google.android.gms.maps.model.CameraPosition
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.tasks.CancellationTokenSource
import com.google.maps.android.compose.*
import org.cdrmapp.citizen.data.Submission
import org.cdrmapp.citizen.ui.CitizenState
import org.cdrmapp.citizen.ui.CitizenViewModel
import org.cdrmapp.citizen.ui.theme.BrandGold
import org.cdrmapp.citizen.ui.theme.BrandGreen
import org.cdrmapp.citizen.ui.theme.CdrmTheme
import org.cdrmapp.citizen.push.PushRegistration
import org.cdrmapp.citizen.push.PushRegistrationState
import kotlinx.coroutines.delay
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.UUID

class MainActivity : ComponentActivity() {
 override fun onCreate(savedInstanceState: Bundle?) {
  super.onCreate(savedInstanceState)
  // Older Play services renderers access LifecycleRegistry during initialization.
  // Initialize on the main thread before Maps Compose can start it on Dispatchers.IO.
  if (BuildConfig.MAPS_KEY.isNotBlank()) MapsInitializer.initialize(this)
  setContent {
  CdrmTheme {
   val vm: CitizenViewModel = viewModel(); val state by vm.state.collectAsStateWithLifecycle()
   Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) { Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().padding(20.dp)) {
    Surface(modifier = Modifier.fillMaxWidth().padding(bottom = 16.dp), color = BrandGreen, contentColor = Color.White, shape = MaterialTheme.shapes.medium) {
     Column(Modifier.padding(16.dp)) {
      Text("CDRMAPP", style = MaterialTheme.typography.headlineMedium)
      Text("Citizen incident reporting", style = MaterialTheme.typography.bodyMedium)
      HorizontalDivider(Modifier.padding(top = 12.dp), thickness = 3.dp, color = BrandGold)
     }
    }
    if (state.busy) LinearProgressIndicator(Modifier.fillMaxWidth().padding(vertical = 8.dp), color = MaterialTheme.colorScheme.secondary)
    if (state.message.isNotBlank()) Surface(modifier = Modifier.fillMaxWidth().padding(vertical = 12.dp), color = MaterialTheme.colorScheme.secondaryContainer, shape = MaterialTheme.shapes.small) {
     Text(state.message, Modifier.padding(12.dp), color = MaterialTheme.colorScheme.onSecondaryContainer)
    }
    if (state.user == null) AuthScreen(vm, state) else CitizenScreen(vm, state)
   } }
  }
 } }
 @Composable private fun CitizenScreen(vm: CitizenViewModel, state: CitizenState) {
  var tab by remember { mutableStateOf("Report") }
  Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
   for (name in listOf("Report", "History", "Profile")) {
    if (name == tab) FilledTonalButton(onClick = { tab = name }, enabled = !state.busy) { Text(name) }
    else TextButton(onClick = { tab = name }, enabled = !state.busy) { Text(name) }
   }
   TextButton(onClick = vm::logout, enabled = !state.busy, colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.tertiary)) { Text("Sign out") }
  }
  if (state.user?.verifiedAt == null) {
   Text("Verify your email before sending incident reports. Use official emergency channels if you need urgent help.")
   Button(onClick = vm::resend, enabled = !state.busy) { Text("Resend verification email") }
   OutlinedButton(onClick = vm::refresh, enabled = !state.busy) { Text("I verified my email — check again") }
   return
  }
  if (BuildConfig.FIREBASE_APPLICATION_ID.isNotBlank()) {
   var permissionGranted by remember { mutableStateOf(android.os.Build.VERSION.SDK_INT < 33 || ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED) }
   val pushState by PushRegistration.state.collectAsStateWithLifecycle()
   val notifyPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> permissionGranted = granted; if (granted) PushRegistration.register(this) }
   if (!permissionGranted) TextButton(onClick = { notifyPermission.launch(Manifest.permission.POST_NOTIFICATIONS) }) { Text("Enable status notifications") }
   if (pushState == PushRegistrationState.Failed) {
    Text("Status notifications could not be registered. Check your connection and retry.")
    TextButton(onClick = { PushRegistration.register(this) }) { Text("Retry notifications") }
   }
  }
  when(tab) { "Report" -> ReportScreen(vm, state); "History" -> HistoryScreen(vm, state); else -> ProfileScreen(vm, state) }
 }
 @Composable private fun ReportScreen(vm: CitizenViewModel, state: CitizenState) {
  var description by remember { mutableStateOf("") }; var categoryId by remember { mutableStateOf<Long?>(null) }
  var position by remember { mutableStateOf<LatLng?>(null) }; var source by remember { mutableStateOf("manual") }
  var accuracy by remember { mutableStateOf<Float?>(null) }; var observedAt by remember { mutableStateOf<String?>(null) }
  var locationMessage by remember { mutableStateOf("Tap the map to choose the incident location, or request your current location.") }
  var confirmSaved by remember { mutableStateOf(false) };var confirmation by remember { mutableStateOf(false) }; var locating by remember { mutableStateOf(false) }
  fun requestLocation() {
   val granted = ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
   if (!granted) { locationMessage = "Location permission denied. Choose a manual incident location on the map."; return }
   locating = true
   try {
    LocationServices.getFusedLocationProviderClient(this).getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, CancellationTokenSource().token)
     .addOnSuccessListener { location ->
      locating = false
      if (location == null || SystemClock.elapsedRealtimeNanos() - location.elapsedRealtimeNanos > 300_000_000_000L || !location.hasAccuracy()) locationMessage = "Fresh location unavailable. Choose a manual incident location."
      else { position = LatLng(location.latitude, location.longitude); source = "gps"; accuracy = location.accuracy; observedAt = Instant.ofEpochMilli(location.time).toString(); locationMessage = "Current location accuracy: ±${location.accuracy.toInt()} m. Confirm the event is here or correct the map pin." }
     }.addOnFailureListener { locating = false; locationMessage = "Location unavailable. Choose a manual incident location." }
   } catch (_: SecurityException) { locating = false; locationMessage = "Permission changed. Choose a manual incident location." }
  }
  val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { requestLocation() }
  Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
   Text("Report an incident", style = MaterialTheme.typography.headlineSmall)
   Text("For urgent help, use official emergency hotlines. Sending a report does not guarantee a response.")
   if (state.pending != null) {
    Card { Column(Modifier.padding(16.dp)) {
     Text("Delivery unconfirmed", style = MaterialTheme.typography.titleMedium)
     Text("A saved report awaits confirmation. Retry sends the same report and identifier, preventing duplicate records.")
     Text(state.pending.description)
     Text("Saved incident coordinates: ${state.pending.latitude}, ${state.pending.longitude}")
     if(state.pending.source=="gps") OutlinedButton(onClick={confirmSaved=true},enabled=!state.busy){Text("Confirm saved location manually")}

     Button(onClick = vm::retry, enabled = !state.busy) { Text("Retry saved report") }
    } }
   } else {
    Text("Choose the appropriate department")
    state.categories.forEach { category -> Row { RadioButton(selected = categoryId == category.id, onClick = { categoryId = category.id }); TextButton(onClick = { categoryId = category.id }) { Text(category.name) } } }
    OutlinedTextField(value = description, onValueChange = { if (it.length <= 5000) description = it }, label = { Text("What happened?") }, minLines = 3, modifier = Modifier.fillMaxWidth())
    OutlinedButton(onClick = { permission.launch(arrayOf(Manifest.permission.ACCESS_COARSE_LOCATION, Manifest.permission.ACCESS_FINE_LOCATION)) }, enabled = !locating) { Text(if (locating) "Requesting location…" else "Use fresh current location") }
    Text(locationMessage)
    if (BuildConfig.MAPS_KEY.isBlank()) Text("Google Maps key is not configured in this build. Reporting requires a selected location; configure the Android Maps key and rebuild.")
    else {
     val camera = rememberCameraPositionState { this.position = CameraPosition.fromLatLngZoom(LatLng(14.8139,121.0453), 13f) }
     LaunchedEffect(position) { position?.let { camera.position = CameraPosition.fromLatLngZoom(it, 16f) } }
     GoogleMap(Modifier.fillMaxWidth().height(280.dp), cameraPositionState = camera, onMapClick = { position = it; source = "manual"; accuracy = null; observedAt = null; locationMessage = "Manually selected incident location. Precision is not measured." }) {
      position?.let { Marker(state = rememberUpdatedMarkerState(position = it), title = "Incident location") }
     }
    }
    position?.let { Text("Incident coordinates: ${it.latitude}, ${it.longitude} · $source") }
    Button(onClick = { confirmation = true }, enabled = !state.busy && categoryId != null && position != null && description.trim().length >= 5, modifier = Modifier.fillMaxWidth().heightIn(min = 60.dp)) { Text("Review and send report") }
   }
  }
  if(confirmSaved) AlertDialog(onDismissRequest={confirmSaved=false},title={Text("Confirm the saved incident location")},text={Text("If GPS is stale, confirm that the saved coordinates still identify the event location. The app first checks for an existing receipt. If already received, the original report is retained; check its details before requesting a correction.")},confirmButton={TextButton(onClick={confirmSaved=false;vm.confirmSavedLocation()}){Text("Confirm location and retry")}},dismissButton={TextButton(onClick={confirmSaved=false}){Text("Cancel")}})
  if (confirmation) AlertDialog(onDismissRequest = { confirmation = false }, title = { Text("Send this incident report?") }, text = { Text("${description.trim()}\n\nLocation: ${position?.latitude}, ${position?.longitude} ($source). Confirm that this is the incident location.") }, confirmButton = { TextButton(onClick = {
   val point = position ?: return@TextButton; val category = categoryId ?: return@TextButton
   confirmation = false
   vm.submit(Submission(category, description.trim(), point.latitude, point.longitude, accuracy, source, observedAt, UUID.randomUUID().toString()))
  }, enabled = !state.busy) { Text("Confirm and send") } }, dismissButton = { TextButton(onClick = { confirmation = false }) { Text("Keep editing") } })
 }
}

@Composable private fun AuthScreen(vm: CitizenViewModel, state: CitizenState) {
 val mode = state.authMode
 var name by remember { mutableStateOf("") }; var email by remember { mutableStateOf("") }; var dob by remember { mutableStateOf("") }; var password by remember { mutableStateOf("") }; var confirm by remember { mutableStateOf("") }
 LaunchedEffect(mode) { password = ""; confirm = ""; if (mode == "Sign in") { name = ""; dob = "" } }
 Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
  Text(mode, style = MaterialTheme.typography.headlineSmall)
  if (mode == "Register") { Field("Full name", name) { name = it }; Field("Date of birth (YYYY-MM-DD)", dob) { dob = it } }
  Field("Email", email) { email = it }
  if (mode != "Reset password") PasswordField("Password", password) { password = it }
  if (mode == "Register") { PasswordField("Confirm password", confirm) { confirm = it }; Text("Use at least 12 characters with letters and numbers.") }
  Button(enabled = !state.busy && email.isNotBlank() && (mode == "Reset password" || password.isNotBlank()), onClick = {
   when(mode) { "Sign in" -> vm.login(email.trim(), password); "Register" -> vm.register(mapOf("name" to name.trim(), "email" to email.trim(), "date_of_birth" to dob, "password" to password, "password_confirmation" to confirm)); else -> vm.forgot(email.trim()) }
  }) { Text(mode) }
  Row { for (choice in listOf("Sign in", "Register", "Reset password")) if (choice != mode) TextButton(onClick = { vm.authMode(choice) }, enabled = !state.busy) { Text(choice) } }
 }
}
@Composable private fun Field(label: String, value: String, changed: (String) -> Unit) { OutlinedTextField(value = value, onValueChange = changed, label = { Text(label) }, modifier = Modifier.fillMaxWidth(), singleLine = true) }
@Composable private fun PasswordField(label: String, value: String, changed: (String) -> Unit) { OutlinedTextField(value = value, onValueChange = changed, label = { Text(label) }, visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth(), singleLine = true) }
@Composable private fun HistoryScreen(vm: CitizenViewModel, state: CitizenState) {
 val lifecycleOwner = LocalLifecycleOwner.current
 LaunchedEffect(lifecycleOwner, vm) {
  lifecycleOwner.lifecycle.repeatOnLifecycle(Lifecycle.State.RESUMED) {
   while (true) { vm.refreshHistory(); delay(15_000) }
  }
 }
 Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
  Text("Your incident history", style = MaterialTheme.typography.headlineSmall)
  OutlinedButton(onClick = vm::refreshHistory, enabled = !state.busy) { Text("Refresh status") }
  if (state.incidents.isEmpty()) Text("No reports received by the server yet.")
  for (incident in state.incidents) Card(onClick = { vm.detail(incident.id) }, modifier = Modifier.fillMaxWidth()) { Column(Modifier.padding(16.dp)) { Text(incident.reference, style = MaterialTheme.typography.titleSmall); Text(incident.description); Text("${reportStatus(incident.status)} · ${localTime(incident.receivedAt)}") } }
  Row { TextButton(onClick = { vm.page(state.page - 1) }, enabled = !state.busy && state.page > 1) { Text("Previous") }; Text("${state.page} / ${state.lastPage}", Modifier.padding(12.dp)); TextButton(onClick = { vm.page(state.page + 1) }, enabled = !state.busy && state.page < state.lastPage) { Text("Next") } }
 }
 state.detail?.let { incident -> AlertDialog(onDismissRequest = vm::closeDetail, title = { Text(incident.reference) }, text = { Column(Modifier.verticalScroll(rememberScrollState())) { Text(incident.description); Text("Status: ${reportStatus(incident.status)}"); for (h in incident.histories.orEmpty()) Text("${localTime(h.at)} · ${reportStatus(h.status)} · ${h.note.orEmpty()}", Modifier.padding(top = 12.dp)) } }, confirmButton = { TextButton(onClick = vm::closeDetail) { Text("Close") } }) }
}
@Composable private fun ProfileScreen(vm: CitizenViewModel, state: CitizenState) {
 var name by remember(state.user?.name) { mutableStateOf(state.user?.name.orEmpty()) }; var dob by remember { mutableStateOf(state.user?.dateOfBirth.orEmpty()) }; var email by remember { mutableStateOf("") }; var current by remember { mutableStateOf("") }; var password by remember { mutableStateOf("") }; var confirm by remember { mutableStateOf("") }
 Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
  Text("Your account", style = MaterialTheme.typography.headlineSmall); Text(state.user?.email.orEmpty())
  Field("Full name", name) { name = it }; Field("Date of birth (YYYY-MM-DD)", dob) { dob = it }; Button(onClick = { vm.profile(name, dob) }, enabled = !state.busy) { Text("Update profile") }
  HorizontalDivider(); PasswordField("Current password", current) { current = it }; Field("New email", email) { email = it }
  OutlinedButton(onClick = { vm.email(email, current) }, enabled = !state.busy && email.isNotBlank() && current.isNotBlank()) { Text("Change email and verify again") }
  PasswordField("New password", password) { password = it }; PasswordField("Confirm new password", confirm) { confirm = it }
  OutlinedButton(onClick = { vm.password(current, password, confirm) }, enabled = !state.busy && current.isNotBlank() && password.length >= 12 && password == confirm) { Text("Change password and sign out") }
 }
}
private fun reportStatus(value: String): String = when (value) { "done", "resolved", "closed", "rejected", "duplicate" -> "Done"; "need_action", "submitted", "received", "verified", "in_progress" -> "Need action"; else -> value.replace('_', ' ') }
private fun localTime(value: String): String = runCatching { DateTimeFormatter.ofPattern("dd MMM yyyy HH:mm z").withZone(ZoneId.of("Asia/Manila")).format(Instant.parse(value)) }.getOrDefault(value)
