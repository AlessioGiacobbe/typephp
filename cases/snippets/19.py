a = {'max': 200}
b = {'min': 100, 'max': 250}
c = {'min': 50}

print(a['min'])
print(a['min'] + b['min'] + c['min'])  # throws KeyError
print(a.get('min', 0) + b.get('min', 0) + c.get('min', 0))  # 150
