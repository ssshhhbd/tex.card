<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление тех. картами</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
    <div class="container mt-5">
        <h1>Настройки приложения</h1>
        <form action="save_settings.php" method="post">
            <div class="form-group">
                <label for="bitrix_webhook_url">URL вебхука Bitrix24</label>
                <input type="text" class="form-control" id="bitrix_webhook_url" name="bitrix_webhook_url" required>
            </div>
            <div class="form-group">
                <label for="deal_stage_for_production">ID стадии сделки для запуска производства</label>
                <input type="text" class="form-control" id="deal_stage_for_production" name="deal_stage_for_production" required>
            </div>
            <button type="submit" class="btn btn-primary">Сохранить настройки</button>
        </form>

        <h2 class="mt-5">Технические карты</h2>
        <form action="save_tech_card.php" method="post">
            <input type="hidden" name="id" id="tech_card_id">
            <div class="form-group">
                <label for="product_name">Название товара</label>
                <input type="text" class="form-control" id="product_name" name="product_name" required>
            </div>
            <div id="materials_container">
                <!-- Поля для материалов будут добавляться сюда динамически -->
            </div>
            <button type="button" class="btn btn-secondary" onclick="addMaterialField()">Добавить материал</button>
            <button type="submit" class="btn btn-primary">Сохранить тех. карту</button>
        </form>

        <h3 class="mt-3">Существующие тех. карты</h3>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Название товара</th>
                    <th>Материалы</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody id="tech_cards_table">
                <!-- Данные будут загружаться сюда -->
            </tbody>
        </table>
    </div>
<script>
        let materialIndex = 0;

        function addMaterialField() {
            const container = document.getElementById('materials_container');
            const newField = document.createElement('div');
            newField.className = 'form-row mb-2';
            newField.innerHTML = `
                <div class="col">
                    <input type="text" class="form-control" name="materials[${materialIndex}][name]" placeholder="Название материала" required>
                </div>
                <div class="col">
                    <input type="number" class="form-control" name="materials[${materialIndex}][quantity]" placeholder="Количество" required>
                </div>
            `;
            container.appendChild(newField);
            materialIndex++;
        }

        function loadTechCards() {
            fetch('tech_cards.json')
                .then(response => response.json())
                .then(data => {
                    const tableBody = document.getElementById('tech_cards_table');
                    tableBody.innerHTML = '';
                    data.forEach(card => {
                        const row = tableBody.insertRow();
                        row.innerHTML = `
                            <td>${card.product_name}</td>
                            <td>${card.materials.map(m => `${m.name}: ${m.quantity}`).join('<br>')}</td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="editTechCard('${card.id}')">Редактировать</button>
                                <button class="btn btn-sm btn-danger" onclick="deleteTechCard('${card.id}')">Удалить</button>
                            </td>
                        `;
                    });
                });
        }

        function editTechCard(id) {
            fetch('tech_cards.json')
                .then(response => response.json())
                .then(data => {
                    const card = data.find(c => c.id === id);
                    if (card) {
                        document.getElementById('tech_card_id').value = card.id;
                        document.getElementById('product_name').value = card.product_name;
                        const container = document.getElementById('materials_container');
                        container.innerHTML = '';
                        materialIndex = 0;
                        card.materials.forEach(material => {
                            addMaterialField();
                            document.querySelector(`[name='materials[${materialIndex-1}][name]']`).value = material.name;
                            document.querySelector(`[name='materials[${materialIndex-1}][quantity]']`).value = material.quantity;
                        });
                    }
                });
        }

        function deleteTechCard(id) {
            if (confirm('Вы уверены, что хотите удалить эту тех. карту?')) {
                fetch(`delete_tech_card.php?id=${id}`)
                    .then(() => loadTechCards());
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadTechCards();
            addMaterialField(); // Добавляем одно поле для материала по умолчанию
        });
    </script>
</body>
</html>