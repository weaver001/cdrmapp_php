package org.cdrmapp.citizen.data

import com.google.gson.annotations.SerializedName
import retrofit2.http.*

data class Envelope<T>(val data: T)
data class Page<T>(val data: List<T>, val meta: PageMeta)
data class PageMeta(@SerializedName("current_page") val currentPage: Int, @SerializedName("last_page") val lastPage: Int)
data class Citizen(val id: Long, val name: String, val email: String, @SerializedName("email_verified_at") val verifiedAt: String?, @SerializedName("date_of_birth") val dateOfBirth: String?)
data class Login(val user: Citizen, val token: String)
data class Category(val id: Long, val name: String, @SerializedName("agency_id") val agencyId: Long?)
data class History(@SerializedName("new_status") val status: String, val note: String?, @SerializedName("created_at") val at: String)
data class Incident(val id: Long, @SerializedName("public_reference") val reference: String, val description: String, @SerializedName("workflow_status") val status: String, @SerializedName("received_at") val receivedAt: String, val latitude: Double, val longitude: Double, val histories: List<History>?)
data class Submission(@SerializedName("category_id") val categoryId: Long, val description: String, val latitude: Double, val longitude: Double, @SerializedName("accuracy_m") val accuracy: Float?, @SerializedName("location_source") val source: String, @SerializedName("location_observed_at") val observedAt: String?, @SerializedName("idempotency_key") val key: String)
interface CdrmApi {
 @POST("auth/register") suspend fun register(@Body fields: Map<String, String>): Envelope<Map<String, Any>>
 @POST("auth/login") suspend fun login(@Body fields: Map<String, String>): Envelope<Login>
 @POST("auth/logout") suspend fun logout(): Envelope<Map<String, String>>
 @POST("auth/forgot-password") suspend fun forgot(@Body fields: Map<String, String>): Envelope<Map<String, String>>
 @POST("auth/verify-email") suspend fun resend(): Envelope<Map<String, String>>
 @GET("me") suspend fun me(): Envelope<Citizen>
 @PATCH("me") suspend fun profile(@Body fields: Map<String, String>): Envelope<Citizen>
 @POST("me/change-password") suspend fun password(@Body fields: Map<String, String>): Envelope<Map<String, String>>
 @POST("me/change-email") suspend fun email(@Body fields: Map<String, String>): Envelope<Map<String, String>>
 @POST("devices") suspend fun device(@Body fields: Map<String, String>): Envelope<Map<String, String>>
 @DELETE("devices") suspend fun revokeDevice(@Query("token") token: String): Envelope<Map<String, String>>
 @GET("incident-categories") suspend fun categories(): Envelope<List<Category>>
 @GET("incidents/receipt/{key}") suspend fun receipt(@Path("key") key: String): Envelope<Incident>
 @POST("incidents") suspend fun submit(@Body incident: Submission): Envelope<Incident>
 @GET("incidents") suspend fun incidents(@Query("page") page: Int = 1): Page<Incident>
 @GET("incidents/{id}") suspend fun incident(@Path("id") id: Long): Envelope<Incident>
}
