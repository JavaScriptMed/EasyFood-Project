package com.example.easylunch.ui.viewmodel

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.example.easylunch.data.model.CatalogCategoryDto
import com.example.easylunch.data.model.MenuDto
import com.example.easylunch.data.network.RetrofitClient
import kotlinx.coroutines.launch

class MenuViewModel : ViewModel() {

    var categories by mutableStateOf<List<CatalogCategoryDto>>(emptyList())
        private set

    var menus by mutableStateOf<List<MenuDto>>(emptyList())
        private set

    var selectedCategoryId by mutableStateOf<Int?>(null)
        private set

    var searchQuery by mutableStateOf("")

    var isLoading by mutableStateOf(false)
        private set

    var errorMessage by mutableStateOf<String?>(null)
        private set

    init {
        loadCategories()
        loadMenus()
    }

    fun loadCategories() {
        viewModelScope.launch {
            try {
                val response = RetrofitClient.apiService.getCategories(isActive = true)
                if (response.isSuccessful) {
                    categories = response.body()?.data ?: emptyList()
                }
            } catch (_: Exception) {}
        }
    }

    fun selectCategory(categoryId: Int?) {
        selectedCategoryId = categoryId
        loadMenus()
    }

    fun onSearchQueryChanged(query: String) {
        searchQuery = query
        loadMenus()
    }

    fun loadMenus() {
        viewModelScope.launch {
            isLoading = true
            errorMessage = null
            try {
                val response = RetrofitClient.apiService.getMenus(
                    categoryId = selectedCategoryId,
                    search = searchQuery.ifEmpty { null },
                    isAvailable = true
                )
                if (response.isSuccessful) {
                    menus = response.body()?.data ?: emptyList()
                } else {
                    errorMessage = "Error al cargar menús: Código ${response.code()}"
                }
            } catch (e: Exception) {
                errorMessage = "Fallo de red: ${e.localizedMessage}"
            } finally {
                isLoading = false
            }
        }
    }

    fun loadMenusDisponiblesParaPedido() {
        viewModelScope.launch {
            isLoading = true
            errorMessage = null
            try {
                val response = RetrofitClient.apiService.getMenus(
                    categoryId = null,
                    search = null,
                    isAvailable = true
                )
                if (response.isSuccessful) {
                    menus = response.body()?.data ?: emptyList()
                } else {
                    errorMessage = "Error al cargar menús: Código ${response.code()}"
                }
            } catch (e: Exception) {
                errorMessage = "Fallo de red: ${e.localizedMessage}"
            } finally {
                isLoading = false
            }
        }
    }
}