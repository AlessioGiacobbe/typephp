import time
import driver
import json

file_username = 'js'
current_time = time.time()  # 获取当前时间
one_day = 86400  # 定义时间间隔为一天（86400秒）
# 检查是否需要重新获取cookies
if 'last_cookie_time' not in driver.__dict__ or current_time - driver.last_cookie_time >= one_day:
    pass

if 'last_cookie_time' not in driver.__dict__ and current_time - driver.last_cookie_time >= one_day:
    pass
