package com.example.easylunch.data.model

// --- CATEGORÍAS ---
data class CatalogCategoryDto(
    val id: Int,
    val code: String,
    val name: String,
    val is_active: Boolean
)

data class PaginatedCategoriesResponse(
    val current_page: Int,
    val data: List<CatalogCategoryDto>,
    val total: Int
)

// --- MENÚS Y PROVEEDORES ---
data class ProviderSummaryDto(
    val id: Int,
    val business_name: String?,
    val commercial_name: String?,
    val phone: String?,
    val address: String?,
    val latitude: Double?,
    val longitude: Double?
)

data class CategorySummaryDto(
    val id: Int,
    val name: String
)

data class MenuDto(
    val id: Int,
    val provider_id: Int,
    val category_id: Int?,
    val name: String,
    val description: String?,
    val price: Double,
    val image_url: String?,
    val daily_stock: Int,
    val is_available: Boolean,
    val available_days: List<String>?,
    val tiempo_comida: String? = null,
    val provider: ProviderSummaryDto?,
    val category: CategorySummaryDto?
)

data class PaginatedMenusResponse(
    val current_page: Int,
    val data: List<MenuDto>,
    val total: Int
)