package org.cdrmapp.citizen.data
import com.google.gson.Gson
import com.google.gson.JsonObject
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import org.cdrmapp.citizen.BuildConfig
import java.util.concurrent.TimeUnit

class SessionStorageException : IllegalStateException("Could not persist the secure session.")
class InvalidCredentialsException : IllegalArgumentException("Incorrect Username/Password")
class OutsideCoverageException(message: String) : IllegalArgumentException(message)

class Repository(private val store: SecretStorage, baseUrl: String = BuildConfig.API_BASE_URL, private val diagnostic: (String) -> Unit = {}) {
 private val gson = Gson()
 private val client = OkHttpClient.Builder().connectTimeout(15, TimeUnit.SECONDS).readTimeout(30, TimeUnit.SECONDS).addInterceptor { chain ->
  val request = chain.request().newBuilder().header("Accept", "application/json")
  val token = store.get("token")
  token?.let { request.header("Authorization", "Bearer $it") }
  val outgoing = request.build()
  val response = chain.proceed(outgoing)
  // Log only routing/status flags. Never log headers, bodies, query strings or token values.
  diagnostic("${outgoing.method} ${outgoing.url.host}${outgoing.url.encodedPath} status=${response.code} tokenSaved=${token != null} bearerSent=${response.request.header("Authorization") != null} finalHost=${response.request.url.host}")
  response
 }.build()
 val api: CdrmApi = Retrofit.Builder().baseUrl(baseUrl).client(client).addConverterFactory(GsonConverterFactory.create(gson)).build().create(CdrmApi::class.java)
 fun hasSession() = store.get("token") != null
 suspend fun login(email: String, password: String): Citizen {
  val result = try {
   api.login(mapOf("email" to email, "password" to password, "device_name" to "Android citizen")).data
  } catch (e: retrofit2.HttpException) {
   if (e.code() == 401 || e.code() == 422) throw InvalidCredentialsException()
   throw e
  }
  require(result.user.id > 0 && result.token.isNotBlank())
  try {
   store.put("token", result.token); store.put("user_id", result.user.id.toString())
   if (store.get("token") != result.token || store.get("user_id") != result.user.id.toString()) throw SessionStorageException()
  } catch (_: Exception) {
   runCatching { clearSession() }
   throw SessionStorageException()
  }
  return result.user
 }
 fun pending(): Submission? = store.get("pending_" + store.get("user_id"))?.let { gson.fromJson(it, Submission::class.java) }
 fun persist(submission: Submission) = store.put("pending_" + store.get("user_id"), gson.toJson(submission))
 fun clearPending() = store.put("pending_" + store.get("user_id"), null)
 suspend fun send(submission: Submission): Incident {
  if (pending() != null) {
   val existing = try { api.receipt(submission.key).data } catch (e: retrofit2.HttpException) { if(e.code()==404) null else throw e }
   if(existing != null) { clearPending();return existing }
  }
  persist(submission) // The original payload/key survives timeouts and process death.
  val result = try {
   api.submit(submission).data
  } catch (e: retrofit2.HttpException) {
   val coverageMessage = if (e.code() == 422) runCatching {
    gson.fromJson(e.response()?.errorBody()?.string(), JsonObject::class.java)
     ?.getAsJsonObject("error")?.getAsJsonObject("fields")?.getAsJsonArray("location")?.firstOrNull()?.asString
   }.getOrNull() else null
   if (!coverageMessage.isNullOrBlank()) {
    // The server definitively rejected this location; it is safe to allow editing.
    // Timeouts and other validation errors retain the exact saved retry payload.
    clearPending()
    throw OutsideCoverageException(coverageMessage)
   }
   throw e
  }
  clearPending() // Only clear after the server acknowledges durable receipt.
  return result
 }
 suspend fun logout() { try { store.get("push_token")?.let { runCatching { api.revokeDevice(it) } }; api.logout() } finally { store.put("token", null); store.put("user_id", null);store.put("push_token",null) } }
 fun clearSession() { store.put("token", null); store.put("user_id", null) }
}
