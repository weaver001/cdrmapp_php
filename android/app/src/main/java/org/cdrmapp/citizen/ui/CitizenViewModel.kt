package org.cdrmapp.citizen.ui
import android.app.Application
import android.util.Log
import org.cdrmapp.citizen.BuildConfig
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import org.cdrmapp.citizen.data.*
import retrofit2.HttpException

data class CitizenState(val user: Citizen? = null, val categories: List<Category> = emptyList(), val incidents: List<Incident> = emptyList(), val detail: Incident? = null, val pending: Submission? = null, val page: Int = 1, val lastPage: Int = 1, val busy: Boolean = false, val message: String = "", val authMode: String = "Sign in")
class CitizenViewModel(application: Application) : AndroidViewModel(application) {
 private val repo = Repository(SecureStore(application), diagnostic = { if (BuildConfig.DEBUG) Log.d("CDRMAPP_AUTH", it) })
 private val mutable = MutableStateFlow(CitizenState())
 val state = mutable.asStateFlow()
 init { if (repo.hasSession()) run { refreshInternal() } }
 private fun run(action: suspend () -> Unit) {
  if (mutable.value.busy) return
  mutable.value = mutable.value.copy(busy = true, message = "")
  viewModelScope.launch {
   try { action() } catch (e: Exception) {
    if (e is kotlinx.coroutines.CancellationException) throw e
    if (e is HttpException && e.code() == 401) { repo.clearSession(); mutable.value = mutable.value.copy(user = null) }
    val message = if (e is SessionStorageException) "This device could not save your secure session. Restart the app and sign in again." else if (e is HttpException) when(e.code()) { 401 -> "Session expired. Sign in again."; 403 -> "Verify your email or check your account access."; 422 -> "Please check the submitted fields. Passwords require 12 characters, letters and numbers. GPS must be fresh."; 409 -> "The saved retry conflicts with a previous request. Contact support with your report reference."; 429 -> "Too many requests. Wait briefly before retrying."; else -> "Server error. Delivery is unconfirmed; retry the saved submission." } else "Connection failed. Delivery is unconfirmed. Your saved report can be retried."
    mutable.value = mutable.value.copy(message = message, pending = repo.pending())
   } finally { mutable.value = mutable.value.copy(busy = false) }
  }
 }
 private suspend fun refreshInternal() {
  val user = repo.api.me().data
  mutable.value = mutable.value.copy(user = user, pending = repo.pending())
  if (user.verifiedAt != null) {
   org.cdrmapp.citizen.push.PushRegistration.register(getApplication())
   val categories = repo.api.categories().data; val list = repo.api.incidents(mutable.value.page)
   mutable.value = mutable.value.copy(categories = categories, incidents = list.data, lastPage = list.meta.lastPage)
  }
 }
 fun login(email: String, password: String) = run { val user = repo.login(email, password); mutable.value = mutable.value.copy(user = user); refreshInternal() }
 fun register(fields: Map<String, String>) = run { repo.api.register(fields); mutable.value = mutable.value.copy(authMode = "Sign in", message = "Account created, Open the verification sent to your email.") }
 fun authMode(mode: String) { if (!mutable.value.busy && mode in listOf("Sign in", "Register", "Reset password")) mutable.value = mutable.value.copy(authMode = mode, message = "") }
 fun forgot(email: String) = run { repo.api.forgot(mapOf("email" to email)); mutable.value = mutable.value.copy(message = "If the account exists, a password reset email has been sent.") }
 fun resend() = run { repo.api.resend(); mutable.value = mutable.value.copy(message = "Verification email requested.") }
 fun refresh() = run { refreshInternal() }
 fun page(value: Int) = run { mutable.value = mutable.value.copy(page = value.coerceIn(1, mutable.value.lastPage)); refreshInternal() }
 fun submit(submission: Submission) = run { val i = repo.send(submission); mutable.value = mutable.value.copy(pending = null, message = "Server received report ${i.reference}. Check the stored report details. This does not guarantee an agency response."); refreshInternal() }
 fun confirmSavedLocation() { repo.pending()?.let { submit(it.copy(source="manual",accuracy=null,observedAt=null)) } }
 fun retry() { repo.pending()?.let { submit(it) } }
 fun detail(id: Long) = run { mutable.value = mutable.value.copy(detail = repo.api.incident(id).data) }
 fun closeDetail() { mutable.value = mutable.value.copy(detail = null) }
 fun profile(name: String, dob: String) = run { mutable.value = mutable.value.copy(user = repo.api.profile(mapOf("name" to name, "date_of_birth" to dob)).data, message = "Profile updated.") }
 fun email(email: String, password: String) = run { repo.api.email(mapOf("email" to email, "current_password" to password)); repo.clearSession(); mutable.value = CitizenState(message = "Verify your new email and sign in again.") }
 fun password(current: String, password: String, confirm: String) = run { repo.api.password(mapOf("current_password" to current, "password" to password, "password_confirmation" to confirm)); repo.clearSession(); mutable.value = CitizenState(message = "Password changed. Sign in again.") }
 fun logout() = run { try { repo.logout() } finally { mutable.value = CitizenState(message = "Signed out. Any unconfirmed report remains saved for this account.") } }
}
