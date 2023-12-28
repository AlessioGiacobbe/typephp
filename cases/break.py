add = "http://c.biancheng.net/python/,http://c.biancheng.net/shell/"
# 一个简单的for循环
for i in add:
    if i == ',':
        # 忽略本次循环的剩下语句
        print('\n')
        continue
    elif i == '.':
        break
    print(i, end="")
