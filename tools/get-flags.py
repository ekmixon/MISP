import urllib.request
import urllib.error
import os

for first_part in [hex(i)[2:] for i in range(0x1F1E6, 0x1F1FF + 1)]:
    for second_part in [hex(i)[2:] for i in range(0x1F1E6, 0x1F1FF + 1)]:
        file_name = f"../app/webroot/img/flags/{first_part}-{second_part}.svg"
        if os.path.exists(file_name):
            continue

        url = f"https://raw.githubusercontent.com/twitter/twemoji/master/assets/svg/{first_part}-{second_part}.svg"

        file_name = f"../app/webroot/img/flags/{first_part}-{second_part}.svg"
        try:
            urllib.request.urlretrieve(url, file_name)
            print(f"Downloaded flag {first_part}-{second_part}")
        except urllib.error.HTTPError:
            pass
