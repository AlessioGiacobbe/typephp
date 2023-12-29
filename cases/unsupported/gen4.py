
def weighted_average(nums, weights):
    return sum(x * y for x, y in zip(nums, weights)) / sum(weights)

weighted_average([1, 2, 3], [0.6, 0.2, 0.3]) # 1.72727
