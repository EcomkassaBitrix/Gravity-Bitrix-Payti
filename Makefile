# Для сборки новой версии модуля запустить `make`.
# Новая версия модуля будет сохранена в файл $OUTPUT

RELEASE=./.last_version/
OUTPUT=./.last_version.tar.gz

release:
	mkdir -p "$(RELEASE)"
	cp ./include.php "$(RELEASE)"
	cp -r ./install "$(RELEASE)"
	cp -r ./lib "$(RELEASE)"
	cp -r ./lang "$(RELEASE)"
	tar czf "$(OUTPUT)" "$(RELEASE)"
