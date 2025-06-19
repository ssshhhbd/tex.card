// Основной класс для работы с техническими картами в Bitrix24
class TechCardManager {
    constructor() {
        this.techCards = [];
        this.init();
    }

    init() {
        this.loadTechCards();
        this.bindEvents();
        this.setupBitrix24();
    }

    setupBitrix24() {
        // Проверяем, что мы находимся в приложении Bitrix24
        if (typeof BX24 !== 'undefined') {
            BX24.init(() => {
                console.log('Bitrix24 приложение инициализировано');
                this.loadCRMStages();
            });
        } else {
            console.warn('Bitrix24 API недоступен. Работаем в демо-режиме.');
        }
    }

    // Загрузка этапов CRM из Bitrix24
    async loadCRMStages() {
        try {
            const result = await this.callBitrix24('crm.status.list', {
                filter: { ENTITY_ID: 'DEAL_STAGE' }
            });
            
            if (result && result.result) {
                this.updateStageSelect(result.result);
            }
        } catch (error) {
            console.error('Ошибка загрузки этапов CRM:', error);
        }
    }

    updateStageSelect(stages) {
        const select = document.getElementById('triggerStage');
        select.innerHTML = '<option value="">Выберите этап...</option>';
        
        stages.forEach(stage => {
            const option = document.createElement('option');
            option.value = stage.STATUS_ID;
            option.textContent = stage.NAME;
            select.appendChild(option);
        });
    }

    // Универсальный метод для вызова Bitrix24 API
    callBitrix24(method, params = {}) {
        return new Promise((resolve, reject) => {
            if (typeof BX24 !== 'undefined') {
                BX24.callMethod(method, params, (result) => {
                    if (result.error()) {
                        reject(result.error());
                    } else {
                        resolve(result);
                    }
                });
            } else {
                // Демо-режим для тестирования
                console.log(`Demo call: ${method}`, params);
                resolve({ result: [] });
            }
        });
    }

    bindEvents() {
        // Добавление нового материала
        document.getElementById('addIngredient').addEventListener('click', () => {
            this.addIngredientRow();
        });

        // Удаление материала
        document.addEventListener('click', (e) => {
            if (e.target.closest('.remove-ingredient')) {
                this.removeIngredientRow(e.target.closest('.ingredient-row'));
            }
        });

        // Сохранение технической карты
        document.getElementById('techCardForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.saveTechCard();
        });
    }

    addIngredientRow() {
        const container = document.getElementById('ingredientsList');
        const newRow = document.createElement('div');
        newRow.className = 'ingredient-row';
        newRow.innerHTML = `
            <div class="row align-items-center">
                <div class="col-md-4">
                    <input type="text" class="form-control ingredient-name" placeholder="Название материала" required>
                </div>
                <div class="col-md-2">
                    <input type="number" class="form-control ingredient-quantity" placeholder="Количество" step="0.01" required>
                </div>
                <div class="col-md-2">
                    <select class="form-select ingredient-unit">
                        <option value="м">метры</option>
                        <option value="кг">килограммы</option>
                        <option value="шт">штуки</option>
                        <option value="л">литры</option>
                        <option value="м²">м²</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="text" class="form-control ingredient-code" placeholder="Код материала в складе">
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-ingredient">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
        container.appendChild(newRow);
    }

    removeIngredientRow(row) {
        const container = document.getElementById('ingredientsList');
        if (container.children.length > 1) {
            row.remove();
        } else {
            alert('Должен остаться хотя бы один материал');
        }
    }

    async saveTechCard() {
        const formData = this.collectFormData();
        
        if (!this.validateFormData(formData)) {
            return;
        }

        try {
            // Сохраняем техническую карту как пользовательский объект в Bitrix24
            const techCard = await this.createTechCardInBitrix24(formData);
            
            // Добавляем в локальный список
            this.techCards.push(techCard);
            
            // Обновляем интерфейс
            this.renderTechCards();
            
            // Очищаем форму
            this.clearForm();
            
            // Показываем уведомление
            this.showNotification('Техническая карта успешно создана!', 'success');
            
        } catch (error) {
            console.error('Ошибка сохранения:', error);
            this.showNotification('Ошибка при сохранении технической карты', 'error');
        }
    }

    collectFormData() {
        const ingredients = [];
        const ingredientRows = document.querySelectorAll('.ingredient-row');
        
        ingredientRows.forEach(row => {
            const name = row.querySelector('.ingredient-name').value;
            const quantity = parseFloat(row.querySelector('.ingredient-quantity').value);
            const unit = row.querySelector('.ingredient-unit').value;
            const code = row.querySelector('.ingredient-code').value;
            
            if (name && quantity) {
                ingredients.push({ name, quantity, unit, code });
            }
        });

        return {
            productName: document.getElementById('productName').value,
            productCode: document.getElementById('productCode').value,
            description: document.getElementById('productDescription').value,
            triggerStage: document.getElementById('triggerStage').value,
            outputQuantity: parseInt(document.getElementById('outputQuantity').value),
            ingredients: ingredients,
            createdAt: new Date().toISOString()
        };
    }

    validateFormData(data) {
        if (!data.productName) {
            alert('Укажите название товара');
            return false;
        }
        
        if (!data.triggerStage) {
            alert('Выберите этап CRM для запуска производства');
            return false;
        }
        
        if (data.ingredients.length === 0) {
            alert('Добавьте хотя бы один материал');
            return false;
        }
        
        return true;
    }

    async createTechCardInBitrix24(formData) {
        try {
            // Создаем пользовательский объект для хранения технических карт
            const result = await this.callBitrix24('crm.item.add', {
                entityTypeId: await this.getOrCreateTechCardEntityType(),
                fields: {
                    title: formData.productName,
                    ufProductCode: formData.productCode,
                    ufDescription: formData.description,
                    ufTriggerStage: formData.triggerStage,
                    ufOutputQuantity: formData.outputQuantity,
                    ufIngredients: JSON.stringify(formData.ingredients),
                    ufCreatedAt: formData.createdAt
                }
            });
            
            return {
                id: result.result,
                ...formData
            };
        } catch (error) {
            console.error('Ошибка создания в Bitrix24:', error);
            // В случае ошибки сохраняем локально
            return {
                id: Date.now(),
                ...formData
            };
        }
    }

    async getOrCreateTechCardEntityType() {
        // Здесь должна быть логика создания пользовательского типа объекта
        // для технических карт, если он еще не существует
        // Для упрощения возвращаем фиксированный ID
        return 1000; // Замените на реальный entityTypeId
    }

    clearForm() {
        document.getElementById('techCardForm').reset();
        
        // Оставляем только одну строку материалов
        const container = document.getElementById('ingredientsList');
        const firstRow = container.querySelector('.ingredient-row');
        container.innerHTML = '';
        container.appendChild(firstRow.cloneNode(true));
        
        // Очищаем поля в оставшейся строке
        const inputs = container.querySelectorAll('input');
        inputs.forEach(input => input.value = '');
    }

    loadTechCards() {
        // Загружаем сохраненные технические карты
        const saved = localStorage.getItem('techCards');
        if (saved) {
            this.techCards = JSON.parse(saved);
            this.renderTechCards();
        }
    }

    renderTechCards() {
        const container = document.getElementById('techCardsList');
        
        if (this.techCards.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>Пока нет созданных технических карт</p>
                    <p class="small">Создайте первую техническую карту выше</p>
                </div>
            `;
            return;
        }

        container.innerHTML = this.techCards.map(card => `
            <div class="tech-card" data-id="${card.id}">
                <div class="row">
                    <div class="col-md-8">
                        <h5 class="mb-2">
                            <i class="fas fa-cog me-2 text-primary"></i>
                            ${card.productName}
                        </h5>
                        <p class="text-muted mb-2">${card.description || 'Без описания'}</p>
                        <div class="mb-2">
                            <span class="badge bg-info me-2">Код: ${card.productCode || 'Не указан'}</span>
                            <span class="badge bg-success me-2">Выход: ${card.outputQuantity} шт</span>
                            <span class="badge bg-warning">Этап: ${card.triggerStage}</span>
                        </div>
                        <div class="ingredients-preview">
                            <strong>Состав:</strong>
                            <ul class="list-unstyled ms-3 mt-1">
                                ${card.ingredients.map(ing => `
                                    <li class="small">
                                        <i class="fas fa-arrow-right me-1 text-muted"></i>
                                        ${ing.name}: ${ing.quantity} ${ing.unit}
                                        ${ing.code ? `(${ing.code})` : ''}
                                    </li>
                                `).join('')}
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="btn-group-vertical" role="group">
                            <button class="btn btn-outline-primary btn-sm edit-card" data-id="${card.id}">
                                <i class="fas fa-edit me-1"></i> Редактировать
                            </button>
                            <button class="btn btn-outline-success btn-sm test-production" data-id="${card.id}">
                                <i class="fas fa-play me-1"></i> Тест производства
                            </button>
                            <button class="btn btn-outline-danger btn-sm delete-card" data-id="${card.id}">
                                <i class="fas fa-trash me-1"></i> Удалить
                            </button>
                        </div>
                        <div class="mt-2 small text-muted">
                            Создано: ${new Date(card.createdAt).toLocaleDateString()}
                        </div>
                    </div>
                </div>
            </div>
        `).join('');

        // Привязываем события для кнопок
        this.bindCardEvents();
        
        // Сохраняем в localStorage
        localStorage.setItem('techCards', JSON.stringify(this.techCards));
    }

    bindCardEvents() {
        // Редактирование
        document.querySelectorAll('.edit-card').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const cardId = e.target.closest('[data-id]').dataset.id;
                this.editTechCard(cardId);
            });
        });

        // Удаление
        document.querySelectorAll('.delete-card').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const cardId = e.target.closest('[data-id]').dataset.id;
                this.deleteTechCard(cardId);
            });
        });

        // Тест производства
        document.querySelectorAll('.test-production').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const cardId = e.target.closest('[data-id]').dataset.id;
                this.testProduction(cardId);
            });
        });
    }

    editTechCard(cardId) {
        const card = this.techCards.find(c => c.id == cardId);
        if (!card) return;

        // Заполняем форму данными карты
        document.getElementById('productName').value = card.productName;
        document.getElementById('productCode').value = card.productCode || '';
        document.getElementById('productDescription').value = card.description || '';
        document.getElementById('triggerStage').value = card.triggerStage;
        document.getElementById('outputQuantity').value = card.outputQuantity;

        // Очищаем и заполняем материалы
        const container = document.getElementById('ingredientsList');
        container.innerHTML = '';
        
        card.ingredients.forEach(ingredient => {
            this.addIngredientRow();
            const lastRow = container.lastElementChild;
            lastRow.querySelector('.ingredient-name').value = ingredient.name;
            lastRow.querySelector('.ingredient-quantity').value = ingredient.quantity;
            lastRow.querySelector('.ingredient-unit').value = ingredient.unit;
            lastRow.querySelector('.ingredient-code').value = ingredient.code || '';
        });

        // Удаляем старую карту
        this.deleteTechCard(cardId, false);
        
        // Прокручиваем к форме
        document.getElementById('techCardForm').scrollIntoView({ behavior: 'smooth' });
    }

    deleteTechCard(cardId, confirm = true) {
        if (confirm && !window.confirm('Удалить техническую карту?')) {
            return;
        }

        this.techCards = this.techCards.filter(c => c.id != cardId);
        this.renderTechCards();
        
        if (confirm) {
            this.showNotification('Техническая карта удалена', 'info');
        }
    }

    async testProduction(cardId) {
        const card = this.techCards.find(c => c.id == cardId);
        if (!card) return;

        try {
            // Симуляция процесса производства
            this.showNotification('Запуск тестового производства...', 'info');
            
            // Здесь должна быть логика:
            // 1. Проверка наличия материалов на складе
            // 2. Списание материалов
            // 3. Добавление готового товара
            
            await this.simulateProduction(card);
            
            this.showNotification(`Тестовое производство завершено! Произведено: ${card.outputQuantity} ${card.productName}`, 'success');
            
        } catch (error) {
            console.error('Ошибка тестового производства:', error);
            this.showNotification('Ошибка при тестовом производстве', 'error');
        }
    }

    async simulateProduction(card) {
        // Симуляция процесса производства
        return new Promise(resolve => {
            setTimeout(() => {
                console.log('Производство:', card);
                console.log('Списываем материалы:', card.ingredients);
                console.log('Добавляем готовый товар:', card.productName, 'x', card.outputQuantity);
                resolve();
            }, 2000);
        });
    }

    showNotification(message, type = 'info') {
        // Создаем уведомление
        const notification = document.createElement('div');
        notification.className = `alert alert-${type === 'error' ? 'danger' : type} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Автоматически удаляем через 5 секунд
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }

    // Метод для обработки изменения этапа сделки в CRM
    async handleDealStageChange(dealId, newStageId) {
        // Находим технические карты, которые должны сработать на этом этапе
        const triggeredCards = this.techCards.filter(card => card.triggerStage === newStageId);
        
        for (const card of triggeredCards) {
            try {
                await this.executeProduction(card, dealId);
            } catch (error) {
                console.error('Ошибка выполнения производства:', error);
            }
        }
    }

    async executeProduction(card, dealId) {
        console.log(`Выполняем производство по карте: ${card.productName} для сделки ${dealId}`);
        
        // Здесь должна быть реальная логика:
        // 1. Получение информации о сделке
        // 2. Проверка наличия материалов
        // 3. Списание материалов со склада
        // 4. Добавление готового товара
        // 5. Обновление сделки
        
        return this.simulateProduction(card);
    }
}

// Инициализация приложения
const techCardManager = new TechCardManager();

// Экспорт для использования в других модулях
if (typeof module !== 'undefined' && module.exports) {
    module.exports = TechCardManager;
}